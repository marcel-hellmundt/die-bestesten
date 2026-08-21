<?php

trait LeagueTrait
{
    public function getMyLeague(): array|false
    {
        $leagueId = $GLOBALS['auth_league_id'] ?? null;
        if ($leagueId) {
            $q = $this->con->prepare(
                "SELECT l.id, l.slug, l.name, l.db_name, l.division_id, l.fine_ruleset
                 FROM league l
                 WHERE l.id = :id LIMIT 1"
            );
            $q->execute([':id' => $leagueId]);
        } else {
            $q = $this->con->prepare(
                "SELECT l.id, l.slug, l.name, l.db_name, l.division_id, l.fine_ruleset
                 FROM league l
                 WHERE l.db_name = :db_name LIMIT 1"
            );
            $q->execute([':db_name' => $_ENV['DB_NAME_LEAGUE']]);
        }
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function updateLeagueDivision(string $id, ?string $divisionId): void
    {
        $q = $this->con->prepare("UPDATE league SET division_id = :division_id WHERE id = :id");
        $q->execute([':division_id' => $divisionId, ':id' => $id]);
    }

    public function updateLeagueVisibility(string $id, string $visibility): void
    {
        $q = $this->con->prepare("UPDATE league SET visibility = :visibility WHERE id = :id");
        $q->execute([':visibility' => $visibility, ':id' => $id]);
    }

    public function updateLeagueFineRuleset(string $id, string $ruleset): void
    {
        $q = $this->con->prepare("UPDATE league SET fine_ruleset = :fine_ruleset WHERE id = :id");
        $q->execute([':fine_ruleset' => $ruleset, ':id' => $id]);
    }

    public function getLeagueList(): array
    {
        $query = $this->con->prepare("SELECT * FROM league ORDER BY name ASC");
        $query->execute();
        $leagues = $query->fetchAll(PDO::FETCH_ASSOC);

        $activeSeasonId = $this->getActiveSeasonId();

        foreach ($leagues as &$league) {
            $league['manager_count'] = $this->getLeagueManagerCount($league['id']);
            $league['team_count']    = $activeSeasonId ? $this->getLeagueTeamCount($league['db_name'], $activeSeasonId) : 0;
        }

        return $leagues;
    }

    public function getLeagueById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM league WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        $league = $query->fetch(PDO::FETCH_ASSOC);
        if ($league) {
            $league['manager_count'] = $this->getLeagueManagerCount($id);
            $league['teams']         = $this->getLeagueTeamList($league['db_name']);
        }
        return $league;
    }

    public function validateLeagueRatings(string $leagueId): array
    {
        $lq = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }

        $con = $this->openLeagueConnection($league['db_name']);
        if (!$con) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        $trRows = $con->query(
            "SELECT tr.id, tr.team_id, tr.matchday_id,
                    tr.points, tr.goals, tr.assists, tr.clean_sheet,
                    tr.sds, tr.sds_defender, tr.red_cards, tr.yellow_red_cards,
                    tr.points_goalkeeper, tr.points_defender, tr.points_midfielder, tr.points_forward,
                    tr.invalid,
                    t.team_name, t.season_id AS team_season_id,
                    m.manager_name
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             JOIN manager m ON m.id = t.manager_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($trRows)) {
            return ['status' => true, 'checked' => 0, 'mismatches' => []];
        }

        $allMatchdayIds = array_values(array_unique(array_column($trRows, 'matchday_id')));
        $allTeamIds     = array_values(array_unique(array_column($trRows, 'team_id')));

        $ph  = implode(',', array_fill(0, count($allMatchdayIds), '?'));
        $mdQ = $this->con->prepare(
            "SELECT md.id, md.number, md.season_id FROM matchday md
             JOIN season s ON s.id = md.season_id
             WHERE md.id IN ($ph) AND s.start_date >= '2020-07-01'"
        );
        $mdQ->execute($allMatchdayIds);
        $matchdayMap = [];
        foreach ($mdQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $matchdayMap[$r['id']] = $r;
        }

        $phT = implode(',', array_fill(0, count($allTeamIds), '?'));
        $phM = implode(',', array_fill(0, count($allMatchdayIds), '?'));
        $luQ = $con->prepare(
            "SELECT team_id, matchday_id, player_id FROM team_lineup
             WHERE nominated = 1 AND team_id IN ($phT) AND matchday_id IN ($phM)"
        );
        $luQ->execute(array_merge($allTeamIds, $allMatchdayIds));
        $lineupMap = [];
        foreach ($luQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lineupMap[$r['team_id']][$r['matchday_id']][] = $r['player_id'];
        }

        $allPlayerIds = [];
        foreach ($lineupMap as $byMd) {
            foreach ($byMd as $pids) {
                foreach ($pids as $pid) $allPlayerIds[] = $pid;
            }
        }
        $allPlayerIds = array_values(array_unique($allPlayerIds));

        $prMap  = [];
        $posMap = [];
        if (!empty($allPlayerIds)) {
            $phP  = implode(',', array_fill(0, count($allPlayerIds), '?'));
            $phM2 = implode(',', array_fill(0, count($allMatchdayIds), '?'));
            $prQ  = $this->con->prepare(
                "SELECT player_id, matchday_id,
                        COALESCE(points, 0) AS points, COALESCE(goals, 0) AS goals,
                        COALESCE(assists, 0) AS assists, COALESCE(clean_sheet, 0) AS clean_sheet,
                        COALESCE(sds, 0) AS sds,
                        COALESCE(red_card, 0) AS red_card,
                        COALESCE(yellow_red_card, 0) AS yellow_red_card
                 FROM player_rating WHERE player_id IN ($phP) AND matchday_id IN ($phM2)"
            );
            $prQ->execute(array_merge($allPlayerIds, $allMatchdayIds));
            foreach ($prQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $prMap[$r['player_id']][$r['matchday_id']] = $r;
            }

            $allSeasonIds = array_values(array_unique(array_column($matchdayMap, 'season_id')));
            if (!empty($allSeasonIds)) {
                $phS  = implode(',', array_fill(0, count($allSeasonIds), '?'));
                $pisQ = $this->con->prepare(
                    "SELECT player_id, season_id, position FROM player_in_season
                     WHERE player_id IN ($phP) AND season_id IN ($phS)"
                );
                $pisQ->execute(array_merge($allPlayerIds, $allSeasonIds));
                foreach ($pisQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $posMap[$r['player_id']][$r['season_id']] = $r['position'];
                }
            }
        }

        $mismatches = [];
        foreach ($trRows as $tr) {
            $md = $matchdayMap[$tr['matchday_id']] ?? null;
            if (!$md) continue; // pre-2020/21 or unknown matchday
            $seasonId = $md['season_id'];
            $players  = $lineupMap[$tr['team_id']][$tr['matchday_id']] ?? [];

            $calcInvalid = empty($players) ? 1 : 0;
            $calcPoints = $calcGoals = $calcAssists = $calcClean = 0;
            $calcSds = $calcSdsDef = $calcRc = $calcYrc = 0;
            $calcGk = $calcDef = $calcMid = $calcFwd = 0;

            foreach ($players as $pid) {
                $pr  = $prMap[$pid][$tr['matchday_id']] ?? null;
                if (!$pr) continue;
                $pos = $posMap[$pid][$seasonId] ?? null;
                $calcPoints += (int) $pr['points'];
                $calcGoals  += (int) $pr['goals'];
                $calcAssists += (int) $pr['assists'];
                $calcClean  += (int) $pr['clean_sheet'];
                $calcSds    += (int) $pr['sds'];
                $calcRc     += (int) $pr['red_card'];
                $calcYrc    += (int) $pr['yellow_red_card'];
                if ($pr['sds'] && in_array($pos, ['GOALKEEPER', 'DEFENDER'])) $calcSdsDef++;
                match ($pos) {
                    'GOALKEEPER' => $calcGk  += (int) $pr['points'],
                    'DEFENDER'   => $calcDef += (int) $pr['points'],
                    'MIDFIELDER' => $calcMid += (int) $pr['points'],
                    'FORWARD'    => $calcFwd += (int) $pr['points'],
                    default      => null,
                };
            }

            $checks = [
                'points'            => [(int) $tr['points'],            $calcPoints],
                'goals'             => [(int) $tr['goals'],             $calcGoals],
                'assists'           => [(int) $tr['assists'],           $calcAssists],
                'clean_sheet'       => [(int) $tr['clean_sheet'],       $calcClean],
                'sds'               => [(int) $tr['sds'],               $calcSds],
                'sds_defender'      => [(int) $tr['sds_defender'],      $calcSdsDef],
                'red_cards'         => [(int) $tr['red_cards'],         $calcRc],
                'yellow_red_cards'  => [(int) $tr['yellow_red_cards'],  $calcYrc],
                'points_goalkeeper' => [(int) $tr['points_goalkeeper'], $calcGk],
                'points_defender'   => [(int) $tr['points_defender'],   $calcDef],
                'points_midfielder' => [(int) $tr['points_midfielder'], $calcMid],
                'points_forward'    => [(int) $tr['points_forward'],    $calcFwd],
            ];

            $diff = [];
            foreach ($checks as $field => [$stored, $calculated]) {
                if ($stored !== $calculated) {
                    $diff[$field] = ['stored' => $stored, 'calculated' => $calculated];
                }
            }
            if (!empty($diff)) {
                $mismatches[] = [
                    'team_id'         => $tr['team_id'],
                    'matchday_id'     => $tr['matchday_id'],
                    'team_name'       => $tr['team_name'],
                    'manager_name'    => $tr['manager_name'],
                    'matchday_number' => (int) $md['number'],
                    'season_id'       => $seasonId,
                    'fields'          => $diff,
                ];
            }
        }

        usort($mismatches, fn($a, $b) => ($a['matchday_number'] ?? 0) <=> ($b['matchday_number'] ?? 0));

        return ['status' => true, 'checked' => count($trRows), 'mismatches' => $mismatches];
    }

    public function fixTeamRatingField(string $leagueId, string $teamId, string $matchdayId, string $field, int $value): array
    {
        $allowedNew = [
            'points', 'goals', 'assists', 'clean_sheet', 'sds', 'sds_defender',
            'red_cards', 'yellow_red_cards',
            'points_goalkeeper', 'points_defender', 'points_midfielder', 'points_forward',
        ];
        if (!in_array($field, $allowedNew, true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Ungültiges Feld'];
        }

        $lq = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(\PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }

        $con = $this->openLeagueConnection($league['db_name']);
        if (!$con) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        $con->prepare("UPDATE team_rating SET $field = ? WHERE team_id = ? AND matchday_id = ?")
            ->execute([$value, $teamId, $matchdayId]);

        return ['status' => true];
    }

    public function sendJoinRequestAdminEmail(string $managerName, string $leagueName): void
    {
        try {
            $adminEmails = $this->con->query(
                "SELECT m.email FROM manager m
                 JOIN manager_role mr ON mr.manager_id = m.id
                 WHERE mr.role = 'admin' AND m.email IS NOT NULL AND m.status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (empty($adminEmails)) return;

            $subject = "Beitrittsanfrage: $managerName — die bestesten";
            $body    = "<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"UTF-8\"></head>"
                . "<body style=\"font-family:sans-serif;color:#1e293b;background:#f8fafc;padding:24px;max-width:600px;margin:0 auto;\">"
                . "<h2 style=\"margin:0 0 12px;\">Neue Beitrittsanfrage</h2>"
                . "<p><strong>" . htmlspecialchars($managerName) . "</strong> möchte der Liga "
                . "<strong>" . htmlspecialchars($leagueName) . "</strong> beitreten.</p>"
                . "<p style=\"color:#64748b;\">Bitte genehmige oder lehne die Anfrage in der Manager-Übersicht ab.</p>"
                . "</body></html>";
            $headers = "From: noreply@die-bestesten.de\r\nContent-Type: text/html; charset=UTF-8";

            foreach ($adminEmails as $email) {
                mail($email, $subject, $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('sendJoinRequestAdminEmail failed: ' . $e->getMessage());
        }
    }

    public function sendInviteAcceptedAdminEmail(string $managerName, string $leagueName): void
    {
        try {
            $adminEmails = $this->con->query(
                "SELECT m.email FROM manager m
                 JOIN manager_role mr ON mr.manager_id = m.id
                 WHERE mr.role = 'admin' AND m.email IS NOT NULL AND m.status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (empty($adminEmails)) return;

            $subject = "Einladung angenommen: $managerName — die bestesten";
            $body    = "<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"UTF-8\"></head>"
                . "<body style=\"font-family:sans-serif;color:#1e293b;background:#f8fafc;padding:24px;max-width:600px;margin:0 auto;\">"
                . "<h2 style=\"margin:0 0 12px;\">Einladung angenommen</h2>"
                . "<p><strong>" . htmlspecialchars($managerName) . "</strong> hat die Einladung zur Liga "
                . "<strong>" . htmlspecialchars($leagueName) . "</strong> angenommen.</p>"
                . "</body></html>";
            $headers = "From: noreply@die-bestesten.de\r\nContent-Type: text/html; charset=UTF-8";

            foreach ($adminEmails as $email) {
                mail($email, $subject, $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('sendInviteAcceptedAdminEmail failed: ' . $e->getMessage());
        }
    }

    private function getLeagueManagerCount(string $leagueId): int
    {
        $q = $this->con->prepare("SELECT COUNT(*) FROM manager_league WHERE league_id = ?");
        $q->execute([$leagueId]);
        return (int) $q->fetchColumn();
    }

    private function getLeagueTeamCount(string $dbName, string $seasonId): int
    {
        $pdo = $this->openLeagueConnection($dbName);
        if (!$pdo) return 0;
        $q = $pdo->prepare("SELECT COUNT(*) FROM team WHERE season_id = ?");
        $q->execute([$seasonId]);
        return (int) $q->fetchColumn();
    }

    private function getLeagueTeamList(string $dbName): array
    {
        try {
            $pdo = $this->openLeagueConnection($dbName);
            if (!$pdo) return [];
            $rows = $pdo->query(
                "SELECT t.id, t.team_name, t.color_primary AS color, t.season_id, t.manager_id,
                        m.manager_name,
                        COALESCE(SUM(tr.points), 0) AS total_points
                 FROM team t
                 JOIN manager m ON m.id = t.manager_id
                 LEFT JOIN team_rating tr ON tr.team_id = t.id
                 GROUP BY t.id, t.team_name, t.color_primary, t.season_id, t.manager_id, m.manager_name
                 ORDER BY t.season_id DESC, t.team_name ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['total_points'] = (int) $row['total_points'];
                $row['color']        = $this->resolveColor($row['color'] ?? null);
            }

            // Active squad size + market value per team (e.g. to show draft-assignment progress
            // in the admin UI) — joined against the global player_in_season table since price
            // isn't stored in the league DB.
            $squadRows = $pdo->query(
                "SELECT team_id, player_id FROM player_in_team WHERE to_matchday_id IS NULL"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $playerIdsByTeam = [];
            foreach ($squadRows as $sr) {
                $playerIdsByTeam[$sr['team_id']][] = $sr['player_id'];
            }

            $priceMap = []; // "playerId:seasonId" => price
            $allPlayerIds = array_values(array_unique(array_column($squadRows, 'player_id')));
            if (!empty($allPlayerIds)) {
                $ph = implode(',', array_fill(0, count($allPlayerIds), '?'));
                $pq = $this->con->prepare(
                    "SELECT player_id, season_id, COALESCE(price, 0) AS price
                     FROM player_in_season WHERE player_id IN ($ph)"
                );
                $pq->execute($allPlayerIds);
                foreach ($pq->fetchAll(\PDO::FETCH_ASSOC) as $pr) {
                    $priceMap[$pr['player_id'] . ':' . $pr['season_id']] = (int) $pr['price'];
                }
            }

            foreach ($rows as &$row) {
                $teamPlayerIds = $playerIdsByTeam[$row['id']] ?? [];
                $squadValue = 0;
                foreach ($teamPlayerIds as $pid) {
                    $squadValue += $priceMap[$pid . ':' . $row['season_id']] ?? 0;
                }
                $row['squad_count'] = count($teamPlayerIds);
                $row['squad_value'] = $squadValue;
            }

            return $rows;
        } catch (\PDOException) {
            return [];
        }
    }

    public function concludeSeasonForLeague(string $leagueId, string $seasonId): array
    {
        $league = $this->getLeagueById($leagueId);
        if (!$league) return ['status' => false, 'message' => 'Liga nicht gefunden'];

        $con = $this->openLeagueConnection($league['db_name']);
        if (!$con) return ['status' => false, 'message' => 'DB-Verbindung fehlgeschlagen'];

        $awardIds = [
            '93e28cd3-07db-11f0-9187-c81f66ca5914', // Meister
            '9f21fdf6-07db-11f0-9187-c81f66ca5914', // Goldene Bürste
            '93e2a7ab-07db-11f0-9187-c81f66ca5914', // Hölzerne Bank
        ];
        $ph = implode(',', array_fill(0, count($awardIds), '?'));

        // Idempotency check
        $existQ = $con->prepare(
            "SELECT COUNT(*) FROM team_award ta JOIN team t ON t.id = ta.team_id
             WHERE t.season_id = ? AND ta.award_id IN ($ph)"
        );
        $existQ->execute([$seasonId, ...$awardIds]);
        if ((int) $existQ->fetchColumn() > 0) {
            return ['status' => true, 'skipped' => true, 'message' => 'Awards bereits vergeben'];
        }

        // Award names from global DB
        $namesQ = $this->con->prepare("SELECT id, name FROM award WHERE id IN ($ph)");
        $namesQ->execute($awardIds);
        $awardNames = array_column($namesQ->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');

        // Meister: highest total points
        $meisterQ = $con->prepare("
            SELECT tr.team_id, t.manager_id, t.team_name
            FROM team_rating tr JOIN team t ON t.id = tr.team_id
            WHERE t.season_id = ? AND tr.invalid = 0
            GROUP BY tr.team_id, t.manager_id, t.team_name
            ORDER BY SUM(tr.points) DESC LIMIT 1
        ");
        $meisterQ->execute([$seasonId]);
        $meister = $meisterQ->fetch(PDO::FETCH_ASSOC);

        // Goldene Bürste: lowest single-matchday points
        $buersteQ = $con->prepare("
            SELECT tr.team_id, t.manager_id, t.team_name
            FROM team_rating tr JOIN team t ON t.id = tr.team_id
            WHERE t.season_id = ? AND tr.invalid = 0
            ORDER BY tr.points ASC LIMIT 1
        ");
        $buersteQ->execute([$seasonId]);
        $goldene = $buersteQ->fetch(PDO::FETCH_ASSOC);

        // Hölzerne Bank: highest total (max_points - points)
        $bankQ = $con->prepare("
            SELECT tr.team_id, t.manager_id, t.team_name
            FROM team_rating tr JOIN team t ON t.id = tr.team_id
            WHERE t.season_id = ? AND tr.invalid = 0
            GROUP BY tr.team_id, t.manager_id, t.team_name
            ORDER BY SUM(tr.max_points - tr.points) DESC LIMIT 1
        ");
        $bankQ->execute([$seasonId]);
        $bank = $bankQ->fetch(PDO::FETCH_ASSOC);

        $toGrant = [
            '93e28cd3-07db-11f0-9187-c81f66ca5914' => $meister,
            '9f21fdf6-07db-11f0-9187-c81f66ca5914' => $goldene,
            '93e2a7ab-07db-11f0-9187-c81f66ca5914' => $bank,
        ];

        $insertAward = $con->prepare(
            "INSERT IGNORE INTO team_award (id, team_id, award_id) VALUES (UUID(), ?, ?)"
        );

        $granted = [];
        foreach ($toGrant as $awardId => $team) {
            if (!$team) continue;
            $insertAward->execute([$team['team_id'], $awardId]);
            $awardName = $awardNames[$awardId] ?? $awardId;
            $this->createNotification(
                $team['manager_id'],
                "Saisonauszeichnung: $awardName",
                null,
                null
            );
            $granted[] = ['award' => $awardName, 'team' => $team['team_name']];
        }

        return ['status' => true, 'skipped' => false, 'granted' => $granted];
    }

    /**
     * League-division players of a season without an active team in THIS league — draft pool
     * for admin-assigned pre-season squads. Unlike getAvailablePlayers(), this is scoped to an
     * explicit $leagueId (not the JWT's auth_league_id), since /daten/league/:id lets an admin
     * manage any league regardless of which one their own token is bound to.
     */
    public function getDraftPool(string $leagueId, string $seasonId): ?array
    {
        $lq = $this->con->prepare("SELECT db_name, division_id FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(PDO::FETCH_ASSOC);
        if (!$league) return null;

        $con = $this->openLeagueConnection($league['db_name']);
        if (!$con) return ['players' => []];

        $excludedIds = [];
        try {
            $ex = $con->prepare(
                "SELECT DISTINCT pit.player_id
                 FROM player_in_team pit
                 JOIN team t ON t.id = pit.team_id
                 WHERE t.season_id = ? AND pit.to_matchday_id IS NULL"
            );
            $ex->execute([$seasonId]);
            $excludedIds = $ex->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {}

        $exclusionClause = '';
        $exclusionParams = [];
        if (!empty($excludedIds)) {
            $ph               = implode(',', array_fill(0, count($excludedIds), '?'));
            $exclusionClause  = "AND p.id NOT IN ($ph)";
            $exclusionParams  = $excludedIds;
        }

        // Division filter: use the league's configured division or fall back to level 1 / DE
        $divisionId = $league['division_id'];
        if ($divisionId !== null) {
            $divisionWhere  = 'AND d.id = ?';
            $divisionParams = [$divisionId];
        } else {
            $divisionWhere  = "AND d.level = 1 AND LOWER(d.country_id) = 'de'";
            $divisionParams = [];
        }

        $stmt = $this->con->prepare(
            "SELECT p.id, p.kicker_id, p.displayname,
                    pis.position, pis.price, pis.photo_uploaded,
                    pic.club_id,
                    c.name AS club_name, c.short_name AS club_short_name,
                    c.logo_uploaded AS club_logo_uploaded
             FROM player_in_season pis
             JOIN player p           ON p.id = pis.player_id
             JOIN player_in_club pic ON pic.player_id = p.id AND pic.to_date IS NULL
             JOIN club c             ON c.id = pic.club_id
             JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
             JOIN division d         ON d.id = cis.division_id
             WHERE pis.season_id = ?
               $divisionWhere
               AND pis.position IS NOT NULL
               AND pis.price IS NOT NULL AND pis.price > 0
               $exclusionClause
             ORDER BY FIELD(pis.position, 'GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'), pis.price DESC"
        );
        $stmt->execute(array_merge([$seasonId], $divisionParams, $exclusionParams));

        return ['players' => array_map(fn($r) => [
            'id'                 => $r['id'],
            'kicker_id'          => $r['kicker_id'] !== null ? (int) $r['kicker_id'] : null,
            'displayname'        => $r['displayname'],
            'position'           => $r['position'],
            'price'              => (int) $r['price'],
            'photo_uploaded'     => (bool) $r['photo_uploaded'],
            'club_id'            => $r['club_id'],
            'club_name'          => $r['club_name'],
            'club_short_name'    => $r['club_short_name'],
            'club_logo_uploaded' => (bool) $r['club_logo_uploaded'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC))];
    }

    /**
     * Bulk pre-season draft assignment: assigns players to any number of teams of one league/season
     * in a single request, replicating BuyTrait::buyPlayer()'s player_in_team + transaction writes
     * (price = exact player_in_season.price) but without team-ownership/transferwindow checks, since
     * an admin is assigning arbitrary teams before any transfer window exists. Squad-limit and
     * duplicate-assignment violations are skipped with a reason instead of aborting the batch —
     * same convention as PlayerInSeasonTrait::importCsvRows().
     */
    public function assignDraftPlayers(string $leagueId, string $seasonId, array $assignments): array
    {
        $lq = $this->con->prepare("SELECT db_name, division_id FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }

        $divisionId = $league['division_id'];
        if ($divisionId !== null) {
            $mdQ = $this->con->prepare(
                "SELECT id FROM matchday WHERE season_id = ? AND division_id = ? AND number = 1 LIMIT 1"
            );
            $mdQ->execute([$seasonId, $divisionId]);
        } else {
            $mdQ = $this->con->prepare(
                "SELECT md.id FROM matchday md
                 JOIN division d ON d.id = md.division_id
                 WHERE md.season_id = ? AND md.number = 1 AND d.level = 1 AND LOWER(d.country_id) = 'de'
                 LIMIT 1"
            );
            $mdQ->execute([$seasonId]);
        }
        $matchdayId = $mdQ->fetchColumn();
        if (!$matchdayId) {
            http_response_code(422);
            return ['status' => false, 'message' => 'Spieltag 1 für diese Division/Saison ist noch nicht angelegt'];
        }

        $con = $this->openLeagueConnection($league['db_name']);
        if (!$con) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        // Players already active on any team this season — spans the whole league/season, not just
        // the teams named in this request, since a player can only ever be on one team at a time.
        $activeQ = $con->prepare(
            "SELECT DISTINCT pit.player_id
             FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             WHERE t.season_id = ? AND pit.to_matchday_id IS NULL"
        );
        $activeQ->execute([$seasonId]);
        $activeSet = array_flip($activeQ->fetchAll(PDO::FETCH_COLUMN));

        $teamPositionCounts = []; // team_id => [position => count]
        $seenInRequest      = []; // player_id => true, across all teams of this request

        $insertPit = $con->prepare(
            "INSERT INTO player_in_team (team_id, player_id, from_matchday_id) VALUES (:tid, :pid, :mid)"
        );
        $insertTx = $con->prepare(
            "INSERT INTO transaction (team_id, amount, reason, matchday_id) VALUES (:tid, :amount, :reason, :mid)"
        );
        $priceQ = $this->con->prepare(
            "SELECT COALESCE(pis.price, 0) AS price, pis.position, p.displayname
             FROM player_in_season pis
             JOIN player p ON p.id = pis.player_id
             WHERE pis.player_id = ? AND pis.season_id = ? LIMIT 1"
        );

        $created    = [];
        $skipped    = [];
        $totalPrice = 0;

        foreach ($assignments as $assignment) {
            $teamId    = $assignment['team_id']    ?? null;
            $playerIds = $assignment['player_ids'] ?? [];
            if (!$teamId || !is_array($playerIds)) continue;

            $tq = $con->prepare("SELECT id FROM team WHERE id = ? AND season_id = ? LIMIT 1");
            $tq->execute([$teamId, $seasonId]);
            if (!$tq->fetchColumn()) {
                foreach ($playerIds as $playerId) {
                    $skipped[] = ['team_id' => $teamId, 'player_id' => $playerId, 'reason' => 'team_not_found'];
                }
                continue;
            }

            if (!isset($teamPositionCounts[$teamId])) {
                $cq = $con->prepare(
                    "SELECT player_id FROM player_in_team WHERE team_id = ? AND to_matchday_id IS NULL"
                );
                $cq->execute([$teamId]);
                $teamPositionCounts[$teamId] = $this->countPositionsForPlayers(
                    $cq->fetchAll(PDO::FETCH_COLUMN), $seasonId
                );
            }

            foreach ($playerIds as $playerId) {
                if (isset($seenInRequest[$playerId])) {
                    $skipped[] = ['team_id' => $teamId, 'player_id' => $playerId, 'reason' => 'duplicate_in_request'];
                    continue;
                }
                if (isset($activeSet[$playerId])) {
                    $skipped[] = ['team_id' => $teamId, 'player_id' => $playerId, 'reason' => 'already_in_team'];
                    continue;
                }

                $priceQ->execute([$playerId, $seasonId]);
                $ps = $priceQ->fetch(PDO::FETCH_ASSOC);
                if (!$ps || !$ps['position'] || !$ps['price']) {
                    $skipped[] = ['team_id' => $teamId, 'player_id' => $playerId, 'reason' => 'no_price_or_position'];
                    continue;
                }

                $position     = $ps['position'];
                $currentCount = $teamPositionCounts[$teamId][$position] ?? 0;
                if (isset(self::SQUAD_MAX[$position]) && $currentCount >= self::SQUAD_MAX[$position]) {
                    $skipped[] = ['team_id' => $teamId, 'player_id' => $playerId, 'reason' => 'position_limit'];
                    continue;
                }

                $price       = (int) round((float) $ps['price']);
                $displayname = $ps['displayname'];

                $insertPit->execute([':tid' => $teamId, ':pid' => $playerId, ':mid' => $matchdayId]);
                $insertTx->execute([
                    ':tid'    => $teamId,
                    ':amount' => -$price,
                    ':reason' => "Draft-Zuweisung: $displayname",
                    ':mid'    => $matchdayId,
                ]);

                $seenInRequest[$playerId] = true;
                $teamPositionCounts[$teamId][$position] = $currentCount + 1;
                $totalPrice += $price;
                $created[] = ['team_id' => $teamId, 'player_id' => $playerId, 'price' => $price];
            }
        }

        return [
            'status'        => true,
            'created_count' => count($created),
            'total_price'   => $totalPrice,
            'skipped'       => $skipped,
        ];
    }

    private function countPositionsForPlayers(array $playerIds, string $seasonId): array
    {
        $counts = ['GOALKEEPER' => 0, 'DEFENDER' => 0, 'MIDFIELDER' => 0, 'FORWARD' => 0];
        if (empty($playerIds)) return $counts;

        $ph = implode(',', array_fill(0, count($playerIds), '?'));
        $q  = $this->con->prepare(
            "SELECT position, COUNT(*) AS cnt FROM player_in_season
             WHERE player_id IN ($ph) AND season_id = ? GROUP BY position"
        );
        $q->execute(array_merge($playerIds, [$seasonId]));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($counts[$row['position']])) $counts[$row['position']] = (int) $row['cnt'];
        }
        return $counts;
    }

    private function openLeagueConnection(string $dbName): ?\PDO
    {
        try {
            $pdo = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$dbName};charset=utf8",
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException) {
            return null;
        }
    }
}
