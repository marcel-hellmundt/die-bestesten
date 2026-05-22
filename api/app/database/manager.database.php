<?php

trait ManagerTrait
{
    public function getAllManagers(): array
    {
        $q = $this->con->prepare(
            "SELECT m.id, m.manager_name, m.alias, m.status,
                    GROUP_CONCAT(DISTINCT mr.role ORDER BY mr.role SEPARATOR ',') AS roles_csv,
                    GROUP_CONCAT(DISTINCT CONCAT(l.id, '~~', l.name, '~~', COALESCE(ml.status, 'active')) ORDER BY l.name SEPARATOR '|') AS league_data
             FROM manager m
             LEFT JOIN manager_role mr  ON mr.manager_id  = m.id
             LEFT JOIN manager_league ml ON ml.manager_id = m.id
             LEFT JOIN league l          ON l.id           = ml.league_id
             GROUP BY m.id, m.manager_name, m.alias, m.status
             ORDER BY
                 CASE WHEN MAX(mr.role = 'admin')      = 1 THEN 0
                      WHEN MAX(mr.role = 'maintainer') = 1 THEN 1
                      ELSE 2 END ASC,
                 m.manager_name ASC"
        );
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['roles']   = $row['roles_csv'] ? explode(',', $row['roles_csv']) : [];
            $row['leagues'] = [];
            if ($row['league_data']) {
                foreach (explode('|', $row['league_data']) as $entry) {
                    [$lid, $lname, $lstatus] = explode('~~', $entry, 3);
                    $row['leagues'][] = ['id' => $lid, 'name' => $lname, 'status' => $lstatus];
                }
            }
            unset($row['roles_csv'], $row['league_data']);
        }
        return $rows;
    }

    public function getManagerById(string $id): array|false
    {
        $q = $this->con->prepare(
            "SELECT id, manager_name, first_name, alias, status, email FROM manager WHERE id = :id LIMIT 1"
        );
        $q->execute([':id' => $id]);
        $manager = $q->fetch(PDO::FETCH_ASSOC);
        if ($manager) $manager['roles'] = $this->getManagerRoles($id);
        return $manager;
    }

    public function updateManagerFirstName(string $id, ?string $firstName): void
    {
        $this->con->prepare(
            "UPDATE manager SET first_name = :first_name WHERE id = :id"
        )->execute([':first_name' => $firstName, ':id' => $id]);
    }

    public function getManagerRoles(string $managerId): array
    {
        $q = $this->con->prepare("SELECT role FROM manager_role WHERE manager_id = :id");
        $q->execute([':id' => $managerId]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function addManagerRole(string $managerId, string $role): void
    {
        $q = $this->con->prepare(
            "INSERT IGNORE INTO manager_role (manager_id, role) VALUES (:manager_id, :role)"
        );
        $q->execute([':manager_id' => $managerId, ':role' => $role]);
    }

    public function removeManagerRole(string $managerId, string $role): void
    {
        $q = $this->con->prepare(
            "DELETE FROM manager_role WHERE manager_id = :manager_id AND role = :role"
        );
        $q->execute([':manager_id' => $managerId, ':role' => $role]);
    }

    public function setManagerRoles(string $managerId, array $roles): void
    {
        $this->con->prepare("DELETE FROM manager_role WHERE manager_id = :id")
            ->execute([':id' => $managerId]);
        foreach ($roles as $role) {
            $this->addManagerRole($managerId, $role);
        }
    }

    public function getManagerWithTeams(string $id): array|false
    {
        $q = $this->con->prepare(
            "SELECT id, manager_name, alias, status FROM manager WHERE id = :id LIMIT 1"
        );
        $q->execute([':id' => $id]);
        $manager = $q->fetch(PDO::FETCH_ASSOC);
        if (!$manager) return false;
        $manager['roles'] = $this->getManagerRoles($id);

        $q = $this->con_league->prepare("
            WITH season_totals AS (
                SELECT t.id, t.season_id, t.manager_id, t.team_name, t.color_primary AS color, t.color_secondary,
                       COALESCE(SUM(tr.points), 0) AS total_points,
                       COUNT(CASE WHEN tr.id IS NOT NULL AND tr.invalid = 0 THEN 1 END) AS matchdays_played,
                       RANK() OVER (PARTITION BY t.season_id ORDER BY COALESCE(SUM(tr.points), 0) DESC) AS season_placement,
                       COUNT(*) OVER (PARTITION BY t.season_id) AS season_team_count
                FROM team t
                LEFT JOIN team_rating tr ON tr.team_id = t.id
                GROUP BY t.id, t.season_id, t.manager_id, t.team_name, t.color_primary, t.color_secondary
            )
            SELECT id, season_id, team_name, color, color_secondary, total_points, matchdays_played,
                   season_placement, season_team_count
            FROM season_totals
            WHERE manager_id = :manager_id
            ORDER BY season_id DESC
        ");
        $q->execute([':manager_id' => $id]);
        $manager['teams'] = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($manager['teams'] as &$t) {
            $t['color']           = $this->resolveColor($t['color']);
            $t['color_secondary'] = $this->resolveColor($t['color_secondary']);
        }
        unset($t);

        // Highlights & Lowlights: top/bottom 5 individual matchday ratings (from 2017/18 onwards)
        $validSeasonIds = array_column(
            $this->con->query("SELECT id FROM season WHERE start_date >= '" . self::STATS_SEASON_START . "'")->fetchAll(PDO::FETCH_ASSOC),
            'id'
        );

        $allRatings = [];
        if (!empty($validSeasonIds)) {
            $seasonPh = implode(',', array_fill(0, count($validSeasonIds), '?'));
            $hlQ = $this->con_league->prepare("
                SELECT tr.points, tr.matchday_id, t.id AS team_id, t.team_name, t.season_id, t.color_primary AS color
                FROM team_rating tr
                JOIN team t ON t.id = tr.team_id
                WHERE t.manager_id = ? AND tr.invalid = 0 AND tr.points IS NOT NULL
                  AND t.season_id IN ($seasonPh)
                ORDER BY tr.points DESC
            ");
            $hlQ->execute(array_merge([$id], $validSeasonIds));
            $allRatings = $hlQ->fetchAll(PDO::FETCH_ASSOC);
        }

        // Resolve matchday numbers from global DB
        if (!empty($allRatings)) {
            $matchdayIds  = array_values(array_unique(array_column($allRatings, 'matchday_id')));
            $placeholders = implode(',', array_fill(0, count($matchdayIds), '?'));
            $mdQ = $this->con->prepare("SELECT id, number FROM matchday WHERE id IN ($placeholders)");
            $mdQ->execute($matchdayIds);
            $mdNumbers = array_column($mdQ->fetchAll(PDO::FETCH_ASSOC), 'number', 'id');
            foreach ($allRatings as &$r) {
                $r['matchday_number'] = $mdNumbers[$r['matchday_id']] ?? null;
                $r['points'] = (int) $r['points'];
                $r['color'] = $this->resolveColor($r['color']);
            }
            unset($r);
        }

        $manager['highlights']  = array_slice($allRatings, 0, 5);
        $manager['lowlights']   = array_slice(array_reverse($allRatings), 0, 5);

        // Favorite players: players bought in ≥ 2 seasons, sorted by total matchdays
        $pitQ = $this->con_league->prepare("
            SELECT pit.player_id, pit.from_matchday_id, pit.to_matchday_id, t.season_id
            FROM player_in_team pit
            JOIN team t ON t.id = pit.team_id
            WHERE t.manager_id = :manager_id
        ");
        $pitQ->execute([':manager_id' => $id]);
        $pitRows = $pitQ->fetchAll(PDO::FETCH_ASSOC);

        $manager['favorite_players'] = [];
        if (!empty($pitRows)) {
            // Collect all matchday IDs to resolve numbers in one query
            $allMdIds = array_values(array_unique(array_filter(array_merge(
                array_column($pitRows, 'from_matchday_id'),
                array_column($pitRows, 'to_matchday_id')
            ))));
            $mdNumbers = [];
            if (!empty($allMdIds)) {
                $ph = implode(',', array_fill(0, count($allMdIds), '?'));
                $mdQ2 = $this->con->prepare("SELECT id, number FROM matchday WHERE id IN ($ph)");
                $mdQ2->execute($allMdIds);
                $mdNumbers = array_column($mdQ2->fetchAll(PDO::FETCH_ASSOC), 'number', 'id');
            }

            // Aggregate per player
            $playerAgg = [];
            foreach ($pitRows as $r) {
                $pid      = $r['player_id'];
                $fromNum  = (int)($mdNumbers[$r['from_matchday_id']] ?? 1);
                if ($r['to_matchday_id'] !== null) {
                    $toNum = isset($mdNumbers[$r['to_matchday_id']]) ? (int)$mdNumbers[$r['to_matchday_id']] : 34;
                } else {
                    // NULL to_matchday: still active — use 34 if not current season
                    $toNum = 34;
                }
                $duration = max(0, $toNum - $fromNum + 1);

                if (!isset($playerAgg[$pid])) {
                    $playerAgg[$pid] = ['total_matchdays' => 0, 'seasons' => []];
                }
                $playerAgg[$pid]['total_matchdays'] += $duration;
                $playerAgg[$pid]['seasons'][$r['season_id']] = true;
            }

            // Filter: ≥ 2 different seasons
            $playerAgg = array_filter($playerAgg, fn($d) => count($d['seasons']) >= 2);
            uasort($playerAgg, fn($a, $b) => $b['total_matchdays'] <=> $a['total_matchdays']);
            $top5Ids = array_slice(array_keys($playerAgg), 0, 5);

            if (!empty($top5Ids)) {
                $ph2 = implode(',', array_fill(0, count($top5Ids), '?'));
                // Get displayname + latest season with photo
                $pQ = $this->con->prepare("
                    SELECT p.id, p.displayname,
                           pis.season_id AS photo_season_id,
                           pis.photo_uploaded
                    FROM player p
                    LEFT JOIN player_in_season pis ON pis.player_id = p.id
                        AND pis.season_id = (
                            SELECT pis2.season_id FROM player_in_season pis2
                            JOIN season s2 ON s2.id = pis2.season_id
                            WHERE pis2.player_id = p.id AND pis2.photo_uploaded = 1
                            ORDER BY s2.start_date DESC
                            LIMIT 1
                        )
                    WHERE p.id IN ($ph2)
                ");
                $pQ->execute($top5Ids);
                $playerInfo = array_column($pQ->fetchAll(PDO::FETCH_ASSOC), null, 'id');

                foreach ($top5Ids as $pid) {
                    $info = $playerInfo[$pid] ?? null;
                    $manager['favorite_players'][] = [
                        'player_id'       => $pid,
                        'displayname'     => $info['displayname'] ?? $pid,
                        'photo_uploaded'  => (bool)($info['photo_uploaded'] ?? false),
                        'photo_season_id' => $info['photo_season_id'] ?? null,
                        'total_matchdays' => $playerAgg[$pid]['total_matchdays'],
                        'season_count'    => count($playerAgg[$pid]['seasons']),
                    ];
                }
            }
        }

        // Achievements: earned by this manager
        $earnedQ = $this->con->prepare(
            "SELECT achievement_id, reason, level FROM manager_achievement WHERE manager_id = ?"
        );
        $earnedQ->execute([$id]);
        $earnedMap = [];
        foreach ($earnedQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $earnedMap[$row['achievement_id']] = [
                'reason' => $row['reason'],
                'level'  => $row['level'],
            ];
        }

        $manager['achievements'] = [];
        if (!empty($earnedMap)) {
            $achIds = array_keys($earnedMap);
            $ph = implode(',', array_fill(0, count($achIds), '?'));
            $achQ = $this->con->prepare(
                "SELECT id, name, icon, threshold_bronze FROM achievement WHERE id IN ($ph)"
            );
            $achQ->execute($achIds);
            $achievements = $achQ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($achievements as $a) {
                $manager['achievements'][] = [
                    'id'              => $a['id'],
                    'name'            => $a['name'],
                    'icon'            => $a['icon'],
                    'threshold_bronze' => $a['threshold_bronze'],
                    'reason'          => $earnedMap[$a['id']]['reason'],
                    'level'           => $earnedMap[$a['id']]['level'],
                ];
            }
            $levelOrder = ['gold' => 0, 'silver' => 1, 'bronze' => 2];
            usort($manager['achievements'], fn($a, $b) => ($levelOrder[$a['level']] ?? 3) <=> ($levelOrder[$b['level']] ?? 3));
        }

        return $manager;
    }

    public function getTeamById(string $id): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT t.id, t.season_id, t.team_name, t.color_primary AS color, t.color_secondary,
                    t.manager_id, m.manager_name, m.alias,
                    COALESCE(SUM(tr.points), 0) AS total_points,
                    COUNT(CASE WHEN tr.id IS NOT NULL AND tr.invalid = 0 THEN 1 END) AS matchdays_played,
                    (SELECT COUNT(*) FROM team t2 WHERE t2.season_id = t.season_id) AS team_count
             FROM team t
             JOIN manager m ON m.id = t.manager_id
             LEFT JOIN team_rating tr ON tr.team_id = t.id
             WHERE t.id = :id
             GROUP BY t.id, t.season_id, t.team_name, t.color_primary, t.color_secondary, t.manager_id, m.manager_name, m.alias
             LIMIT 1"
        );
        $q->execute([':id' => $id]);
        $team = $q->fetch(PDO::FETCH_ASSOC);
        if ($team) {
            $team['color']           = $this->resolveColor($team['color']);
            $team['color_secondary'] = $this->resolveColor($team['color_secondary']);
        }
        return $team;
    }

    public function getTeamsBySeason(string $seasonId): array
    {
        $q = $this->con_league->prepare(
            "SELECT t.id, t.team_name, t.color_primary AS color, t.color_secondary, t.season_id,
                    t.manager_id, m.manager_name, m.alias
             FROM team t
             JOIN manager m ON m.id = t.manager_id
             WHERE t.season_id = :s
             ORDER BY t.team_name"
        );
        $q->execute([':s' => $seasonId]);
        $teams = $q->fetchAll(PDO::FETCH_ASSOC);

        if (empty($teams)) return [];

        foreach ($teams as &$t) {
            $t['color']           = $this->resolveColor($t['color'] ?? null);
            $t['color_secondary'] = $this->resolveColor($t['color_secondary'] ?? null);
        }
        unset($t);

        // Active players per team (league DB)
        $teamIds = array_column($teams, 'id');
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $pitQ = $this->con_league->prepare(
            "SELECT team_id, player_id FROM player_in_team WHERE team_id IN ($ph) AND to_matchday_id IS NULL"
        );
        $pitQ->execute($teamIds);
        $playerInTeam = $pitQ->fetchAll(PDO::FETCH_ASSOC);

        // Group player IDs by team
        $teamPlayerIds = array_fill_keys($teamIds, []);
        $allPlayerIds  = [];
        foreach ($playerInTeam as $row) {
            $teamPlayerIds[$row['team_id']][] = $row['player_id'];
            $allPlayerIds[] = $row['player_id'];
        }

        // Get positions + prices from global DB
        $positions = [];
        $prices    = [];
        if (!empty($allPlayerIds)) {
            $allPlayerIds = array_unique($allPlayerIds);
            $pp = implode(',', array_fill(0, count($allPlayerIds), '?'));
            $pisQ = $this->con->prepare(
                "SELECT player_id, position, price FROM player_in_season WHERE player_id IN ($pp) AND season_id = ?"
            );
            $pisQ->execute([...$allPlayerIds, $seasonId]);
            foreach ($pisQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $positions[$row['player_id']] = $row['position'];
                $prices[$row['player_id']]    = (float) $row['price'];
            }
        }

        // Validate each squad: minimums GK≥1 DEF≥5 MID≥5 FWD≥3; sum total_value
        $sqMin = ['GOALKEEPER' => 1, 'DEFENDER' => 5, 'MIDFIELDER' => 5, 'FORWARD' => 3];
        foreach ($teams as &$team) {
            $counts     = ['GOALKEEPER' => 0, 'DEFENDER' => 0, 'MIDFIELDER' => 0, 'FORWARD' => 0];
            $totalValue = 0.0;
            foreach ($teamPlayerIds[$team['id']] as $pid) {
                $pos = $positions[$pid] ?? null;
                if ($pos && isset($counts[$pos])) $counts[$pos]++;
                $totalValue += $prices[$pid] ?? 0.0;
            }
            $valid = true;
            foreach ($sqMin as $pos => $min) {
                if ($counts[$pos] < $min) { $valid = false; break; }
            }
            $team['squad_valid'] = $valid;
            $team['total_value'] = $totalValue;
        }
        unset($team);

        return $teams;
    }

    public function getMyTeamForActiveSeason(string $managerId): array|false
    {
        $activeSeasonId = $this->getActiveSeasonId();
        if (!$activeSeasonId) return false;

        $q = $this->con_league->prepare(
            "SELECT id, team_name, season_id, color_primary AS color, color_secondary FROM team
             WHERE manager_id = :manager_id AND season_id = :season_id
             LIMIT 1"
        );
        $q->execute([':manager_id' => $managerId, ':season_id' => $activeSeasonId]);
        $team = $q->fetch(PDO::FETCH_ASSOC);
        if ($team) {
            $team['color']           = $this->resolveColor($team['color']);
            $team['color_secondary'] = $this->resolveColor($team['color_secondary']);
        }
        return $team;
    }

    public function teamExistsForManagerActiveSeason(string $managerId): bool
    {
        $activeSeasonId = $this->getActiveSeasonId();
        if (!$activeSeasonId) return false;
        $q = $this->con_league->prepare(
            "SELECT COUNT(*) FROM team WHERE manager_id = :m AND season_id = :s"
        );
        $q->execute([':m' => $managerId, ':s' => $activeSeasonId]);
        return (int) $q->fetchColumn() > 0;
    }

    public function createTeam(string $id, string $managerId, string $teamName, ?string $colorPrimary, ?string $colorSecondary): void
    {
        $activeSeasonId = $this->getActiveSeasonId();
        $q = $this->con_league->prepare(
            "INSERT INTO team (id, manager_id, season_id, team_name, color_primary, color_secondary)
             VALUES (:id, :m, :s, :team_name, :color_primary, :color_secondary)"
        );
        $q->execute([
            ':id'             => $id,
            ':m'              => $managerId,
            ':s'              => $activeSeasonId,
            ':team_name'      => $teamName,
            ':color_primary'  => $colorPrimary,
            ':color_secondary' => $colorSecondary,
        ]);
    }

    public function isTeamNameTaken(string $teamName): bool
    {
        $activeSeasonId = $this->getActiveSeasonId();
        if (!$activeSeasonId) return false;
        $q = $this->con_league->prepare(
            "SELECT COUNT(*) FROM team WHERE team_name = :name AND season_id = :s"
        );
        $q->execute([':name' => $teamName, ':s' => $activeSeasonId]);
        return (int) $q->fetchColumn() > 0;
    }

    public function getPreviousTeam(string $managerId): array|false
    {
        $seasons = $this->con->query("SELECT id FROM season WHERE start_date <= CURDATE() ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
        if (count($seasons) < 2) return false;

        $prevSeasons  = array_slice($seasons, 1);
        $placeholders = implode(',', array_fill(0, count($prevSeasons), '?'));

        $q = $this->con_league->prepare(
            "SELECT id, team_name, color_primary AS color, color_secondary, season_id FROM team
             WHERE manager_id = ? AND season_id IN ($placeholders)"
        );
        $q->execute([$managerId, ...$prevSeasons]);
        $teams = $q->fetchAll(PDO::FETCH_ASSOC);
        if (empty($teams)) return false;

        $teamBySeason = array_column($teams, null, 'season_id');
        foreach ($prevSeasons as $sid) {
            if (isset($teamBySeason[$sid])) {
                $t = $teamBySeason[$sid];
                $t['color']           = $this->resolveColor($t['color']);
                $t['color_secondary'] = $this->resolveColor($t['color_secondary']);
                return $t;
            }
        }
        return false;
    }

    public function getTeamRatings(string $teamId): array
    {
        // Use window functions to compute placement and fine across all teams per matchday
        $q = $this->con_league->prepare("
            WITH valid_ranked AS (
                SELECT team_id, matchday_id,
                       RANK()       OVER (PARTITION BY matchday_id ORDER BY points DESC) AS placement,
                       DENSE_RANK() OVER (PARTITION BY matchday_id ORDER BY points ASC)  AS rank_asc
                FROM team_rating
                WHERE invalid = 0
                  AND matchday_id IN (SELECT matchday_id FROM team_rating WHERE team_id = :team_id_sub)
            ),
            md_invalid AS (
                SELECT matchday_id, SUM(invalid) AS invalid_cnt
                FROM team_rating
                WHERE matchday_id IN (SELECT matchday_id FROM team_rating WHERE team_id = :team_id_sub2)
                GROUP BY matchday_id
            )
            SELECT tr.id, tr.matchday_id, tr.points, tr.max_points,
                   tr.goals, tr.assists, tr.red_cards, tr.yellow_red_cards, tr.clean_sheet, tr.sds,
                   tr.sds_defender, tr.missed_goals, tr.invalid,
                   vr.placement,
                   CASE
                       WHEN tr.invalid = 1              THEN 3.00
                       WHEN COALESCE(mi.invalid_cnt, 0) > 0 THEN
                           CASE WHEN vr.rank_asc = 1 THEN 2.00 WHEN vr.rank_asc = 2 THEN 1.50 WHEN vr.rank_asc = 3 THEN 1.00 ELSE 0 END
                       ELSE
                           CASE WHEN vr.rank_asc = 1 THEN 3.00 WHEN vr.rank_asc = 2 THEN 2.00 WHEN vr.rank_asc = 3 THEN 1.50 WHEN vr.rank_asc = 4 THEN 1.00 ELSE 0 END
                   END AS fine
            FROM team_rating tr
            LEFT JOIN valid_ranked vr ON vr.team_id = tr.team_id AND vr.matchday_id = tr.matchday_id
            LEFT JOIN md_invalid    mi ON mi.matchday_id = tr.matchday_id
            WHERE tr.team_id = :team_id
        ");
        $q->execute([':team_id' => $teamId, ':team_id_sub' => $teamId, ':team_id_sub2' => $teamId]);
        $ratings = $q->fetchAll(PDO::FETCH_ASSOC);

        if (empty($ratings)) return [];

        $matchdayIds  = array_column($ratings, 'matchday_id');
        $placeholders = implode(',', array_fill(0, count($matchdayIds), '?'));
        $mq = $this->con->prepare(
            "SELECT id, number, kickoff_date, completed FROM matchday WHERE id IN ($placeholders)"
        );
        $mq->execute($matchdayIds);
        $matchdayMap = array_column($mq->fetchAll(PDO::FETCH_ASSOC), null, 'id');

        $ratings = array_values(array_filter($ratings, fn($r) => (bool)($matchdayMap[$r['matchday_id']]['completed'] ?? false)));

        foreach ($ratings as &$r) {
            $md = $matchdayMap[$r['matchday_id']] ?? null;
            $r['matchday_number'] = $md ? (int)$md['number'] : null;
            $r['kickoff_date']    = $md ? $md['kickoff_date'] : null;
            $r['fine']            = (float)($r['fine'] ?? 0.0);
            $r['placement']       = $r['placement'] !== null ? (int)$r['placement'] : null;
        }
        unset($r);

        usort($ratings, fn($a, $b) => ($a['matchday_number'] ?? 0) <=> ($b['matchday_number'] ?? 0));

        // Compute running cumulative rank per matchday
        $allRatingsQ = $this->con_league->prepare(
            "SELECT team_id, matchday_id, points, invalid FROM team_rating WHERE matchday_id IN ($placeholders)"
        );
        $allRatingsQ->execute($matchdayIds);
        $byMatchday = [];
        foreach ($allRatingsQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byMatchday[$r['matchday_id']][] = $r;
        }

        $cumPoints = []; // team_id → cumulative points
        foreach ($ratings as &$r) {
            foreach ($byMatchday[$r['matchday_id']] ?? [] as $row) {
                if (!$row['invalid']) {
                    $cumPoints[$row['team_id']] = ($cumPoints[$row['team_id']] ?? 0) + (int)$row['points'];
                }
            }
            // RANK() — ties share the same rank
            $sorted = $cumPoints;
            arsort($sorted);
            $rank = 0; $count = 0; $prevPts = null; $teamRank = null;
            foreach ($sorted as $tid => $pts) {
                $count++;
                if ($pts !== $prevPts) $rank = $count;
                if ($tid === $teamId) { $teamRank = $rank; break; }
                $prevPts = $pts;
            }
            $r['running_rank'] = $teamRank;
        }
        unset($r);

        return $ratings;
    }

    public function updateManagerPassword(string $id, string $hashedPassword): bool
    {
        $q = $this->con->prepare(
            "UPDATE manager SET password = :password WHERE id = :id"
        );
        $q->execute([':password' => $hashedPassword, ':id' => $id]);
        return $q->rowCount() > 0;
    }

    public function updateManagerEmail(string $id, ?string $email): bool
    {
        $q = $this->con->prepare(
            "UPDATE manager SET email = :email WHERE id = :id"
        );
        $q->execute([':email' => $email, ':id' => $id]);
        return true;
    }

    public function markManagerDeleted(string $id): void
    {
        $q = $this->con->prepare(
            "UPDATE manager SET status = 'deleted' WHERE id = :id"
        );
        $q->execute([':id' => $id]);
    }
}
