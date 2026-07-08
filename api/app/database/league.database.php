<?php

trait LeagueTrait
{
    public function getMyLeague(): array|false
    {
        $q = $this->con->prepare(
            "SELECT l.id, l.slug, l.name, l.db_name, l.division_id
             FROM league l
             WHERE l.db_name = :db_name LIMIT 1"
        );
        $q->execute([':db_name' => $_ENV['DB_NAME_LEAGUE']]);
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

    public function getLeagueList(): array
    {
        $query = $this->con->prepare("SELECT * FROM league ORDER BY name ASC");
        $query->execute();
        $leagues = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leagues as &$league) {
            $league['manager_count'] = $this->getLeagueManagerCount($league['id']);
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
