<?php

trait ManagerTrait
{
    public function getManagerById(string $id): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT id, manager_name, alias, role, status, email FROM manager WHERE id = :id LIMIT 1"
        );
        $q->execute([':id' => $id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function getManagerWithTeams(string $id): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT id, manager_name, alias, role, status FROM manager WHERE id = :id LIMIT 1"
        );
        $q->execute([':id' => $id]);
        $manager = $q->fetch(PDO::FETCH_ASSOC);
        if (!$manager) return false;

        $q = $this->con_league->prepare("
            WITH season_totals AS (
                SELECT t.id, t.season_id, t.manager_id, t.team_name, t.color,
                       COALESCE(SUM(tr.points), 0) AS total_points,
                       COUNT(CASE WHEN tr.id IS NOT NULL AND tr.invalid = 0 THEN 1 END) AS matchdays_played,
                       RANK() OVER (PARTITION BY t.season_id ORDER BY COALESCE(SUM(tr.points), 0) DESC) AS season_placement,
                       COUNT(*) OVER (PARTITION BY t.season_id) AS season_team_count
                FROM team t
                LEFT JOIN team_rating tr ON tr.team_id = t.id
                GROUP BY t.id, t.season_id, t.manager_id, t.team_name, t.color
            )
            SELECT id, season_id, team_name, color, total_points, matchdays_played,
                   season_placement, season_team_count
            FROM season_totals
            WHERE manager_id = :manager_id
            ORDER BY season_id DESC
        ");
        $q->execute([':manager_id' => $id]);
        $manager['teams'] = $q->fetchAll(PDO::FETCH_ASSOC);

        // Highlights & Lowlights: top/bottom 5 individual matchday ratings for this manager
        $hlQ = $this->con_league->prepare("
            SELECT tr.points, tr.matchday_id, t.id AS team_id, t.team_name, t.season_id, t.color
            FROM team_rating tr
            JOIN team t ON t.id = tr.team_id
            WHERE t.manager_id = :manager_id AND tr.invalid = 0 AND tr.points IS NOT NULL
            ORDER BY tr.points DESC
        ");
        $hlQ->execute([':manager_id' => $id]);
        $allRatings = $hlQ->fetchAll(PDO::FETCH_ASSOC);

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
            }
            unset($r);
        }

        $manager['highlights']  = array_slice($allRatings, 0, 5);
        $manager['lowlights']   = array_slice(array_reverse($allRatings), 0, 5);

        // Favorite players: players bought in ≥ 2 seasons, sorted by total matchdays
        $activeSeasonId = $this->con->query(
            "SELECT id FROM season ORDER BY start_date DESC LIMIT 1"
        )->fetchColumn();

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
                $fromNum  = isset($mdNumbers[$r['from_matchday_id']]) ? (int)$mdNumbers[$r['from_matchday_id']] : 1;
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

        return $manager;
    }

    public function getTeamById(string $id): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT t.id, t.season_id, t.team_name, t.color,
                    t.manager_id, m.manager_name, m.alias,
                    COALESCE(SUM(tr.points), 0) AS total_points,
                    COUNT(CASE WHEN tr.id IS NOT NULL AND tr.invalid = 0 THEN 1 END) AS matchdays_played
             FROM team t
             JOIN manager m ON m.id = t.manager_id
             LEFT JOIN team_rating tr ON tr.team_id = t.id
             WHERE t.id = :id
             GROUP BY t.id, t.season_id, t.team_name, t.color, t.manager_id, m.manager_name, m.alias
             LIMIT 1"
        );
        $q->execute([':id' => $id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function getTeamRatings(string $teamId): array
    {
        // Use window functions to compute placement and fine across all teams per matchday
        $q = $this->con_league->prepare("
            WITH ranked AS (
                SELECT team_id, matchday_id, points, invalid,
                       RANK() OVER (PARTITION BY matchday_id ORDER BY points DESC) AS placement,
                       RANK() OVER (PARTITION BY matchday_id ORDER BY points ASC)  AS rank_asc
                FROM team_rating
                WHERE invalid = 0
                  AND matchday_id IN (SELECT matchday_id FROM team_rating WHERE team_id = :team_id_sub)
            )
            SELECT tr.id, tr.matchday_id, tr.points, tr.max_points,
                   tr.goals, tr.assists, tr.clean_sheet, tr.sds,
                   tr.sds_defender, tr.missed_goals, tr.invalid,
                   r.placement,
                   CASE r.rank_asc
                       WHEN 1 THEN 3.00 WHEN 2 THEN 2.00
                       WHEN 3 THEN 1.50 WHEN 4 THEN 1.00
                       ELSE 0
                   END AS fine
            FROM team_rating tr
            LEFT JOIN ranked r ON r.team_id = tr.team_id AND r.matchday_id = tr.matchday_id
            WHERE tr.team_id = :team_id
        ");
        $q->execute([':team_id' => $teamId, ':team_id_sub' => $teamId]);
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
        $q = $this->con_league->prepare(
            "UPDATE manager SET password = :password WHERE id = :id"
        );
        $q->execute([':password' => $hashedPassword, ':id' => $id]);
        return $q->rowCount() > 0;
    }

    public function updateManagerEmail(string $id, ?string $email): bool
    {
        $q = $this->con_league->prepare(
            "UPDATE manager SET email = :email WHERE id = :id"
        );
        $q->execute([':email' => $email, ':id' => $id]);
        return true;
    }

    public function markManagerDeleted(string $id): void
    {
        $q = $this->con_league->prepare(
            "UPDATE manager SET status = 'deleted' WHERE id = :id"
        );
        $q->execute([':id' => $id]);
    }
}
