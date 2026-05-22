<?php

trait MatchdayTrait
{
    public function getMatchdayList(?string $seasonId = null): array
    {
        if ($seasonId) {
            $divisionId = $this->getLeagueDivisionId();
            if ($divisionId !== null) {
                $query = $this->con->prepare("
                    SELECT m.*,
                           EXISTS(SELECT 1 FROM player_rating pr WHERE pr.matchday_id = m.id) AS has_ratings
                    FROM matchday m
                    WHERE m.season_id = :season_id AND m.division_id = :division_id
                    ORDER BY m.number DESC
                ");
                $query->execute([':season_id' => $seasonId, ':division_id' => $divisionId]);
            } else {
                $query = $this->con->prepare("
                    SELECT m.*,
                           EXISTS(SELECT 1 FROM player_rating pr WHERE pr.matchday_id = m.id) AS has_ratings
                    FROM matchday m
                    JOIN division d ON d.id = m.division_id
                    WHERE m.season_id = :season_id AND d.level = 1 AND LOWER(d.country_id) = 'de'
                    ORDER BY m.number DESC
                ");
                $query->execute([':season_id' => $seasonId]);
            }
        } else {
            $query = $this->con->prepare("SELECT * FROM matchday ORDER BY start_date DESC");
            $query->execute();
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createMatchday(string $seasonId, int $number, string $startDate, string $kickoffDate): string
    {
        $divisionId = $this->getLeagueDivisionId();
        if (!$divisionId) {
            throw new \RuntimeException('Liga hat keine Division konfiguriert');
        }
        $id = $this->generateGUID();
        $this->con->prepare(
            "INSERT INTO matchday (id, season_id, division_id, number, start_date, kickoff_date)
             VALUES (:id, :season_id, :division_id, :number, :start_date, :kickoff_date)"
        )->execute([
            ':id'           => $id,
            ':season_id'    => $seasonId,
            ':division_id'  => $divisionId,
            ':number'       => $number,
            ':start_date'   => $startDate,
            ':kickoff_date' => $kickoffDate,
        ]);
        return $id;
    }

    public function getMatchdayById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM matchday WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function updateMatchdayCompleted(string $id, bool $completed): bool
    {
        $query = $this->con->prepare(
            "UPDATE matchday SET completed = :completed WHERE id = :id"
        );
        $query->execute([':completed' => $completed ? 1 : 0, ':id' => $id]);
        return $query->rowCount() > 0;
    }

    public function finalizeMatchday(string $matchdayId): int
    {
        $updatedCount = 0;
        $matchday = $this->getMatchdayById($matchdayId);
        if (!$matchday) return 0;
        $seasonId    = $matchday['season_id'];
        $matchdayNum = (int) $matchday['number'];
        $kickoffDate = $matchday['kickoff_date'];

        $sQ = $this->con->prepare("SELECT start_date FROM season WHERE id = ? LIMIT 1");
        $sQ->execute([$seasonId]);
        $startYear   = (int) substr((string) $sQ->fetchColumn(), 0, 4);
        $seasonLabel = $startYear . '-' . ($startYear + 1);

        $teamsQ = $this->con_league->prepare(
            "SELECT id, manager_id FROM team WHERE season_id = ?"
        );
        $teamsQ->execute([$seasonId]);
        $teams = $teamsQ->fetchAll(PDO::FETCH_ASSOC);
        if (empty($teams)) return 0;

        $lineupQ = $this->con_league->prepare(
            "SELECT team_id, player_id, nominated FROM team_lineup WHERE matchday_id = ?"
        );
        $lineupQ->execute([$matchdayId]);
        $lineupRows = $lineupQ->fetchAll(PDO::FETCH_ASSOC);

        $lineupByTeam = [];
        foreach ($lineupRows as $row) {
            $lineupByTeam[$row['team_id']][] = $row;
        }

        $ratingByPlayer = [];
        $allPlayerIds   = array_unique(array_column($lineupRows, 'player_id'));
        if (!empty($allPlayerIds)) {
            $ph  = implode(',', array_fill(0, count($allPlayerIds), '?'));
            $prQ = $this->con->prepare(
                "SELECT pr.player_id,
                        COALESCE(pr.points, 0)          AS points,
                        COALESCE(pr.goals, 0)           AS goals,
                        COALESCE(pr.assists, 0)         AS assists,
                        COALESCE(pr.red_card, 0)        AS red_card,
                        COALESCE(pr.yellow_red_card, 0) AS yellow_red_card,
                        COALESCE(pr.clean_sheet, 0)     AS clean_sheet,
                        COALESCE(pr.sds, 0)             AS sds,
                        pis.position
                 FROM player_rating pr
                 LEFT JOIN player_in_season pis
                        ON pis.player_id = pr.player_id AND pis.season_id = ?
                 WHERE pr.matchday_id = ? AND pr.player_id IN ($ph)"
            );
            $prQ->execute(array_merge([$seasonId, $matchdayId], array_values($allPlayerIds)));
            $ratingByPlayer = array_column($prQ->fetchAll(PDO::FETCH_ASSOC), null, 'player_id');
        }

        $insertRating = $this->con_league->prepare(
            "INSERT INTO team_rating
                 (id, team_id, matchday_id, points, max_points, goals, assists,
                  red_cards, yellow_red_cards, clean_sheet, sds, sds_defender, missed_goals,
                  points_goalkeeper, points_defender, points_midfielder, points_forward, invalid)
             VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 points           = VALUES(points),
                 max_points       = VALUES(max_points),
                 goals            = VALUES(goals),
                 assists          = VALUES(assists),
                 red_cards        = VALUES(red_cards),
                 yellow_red_cards = VALUES(yellow_red_cards),
                 clean_sheet      = VALUES(clean_sheet),
                 sds              = VALUES(sds),
                 sds_defender     = VALUES(sds_defender),
                 points_goalkeeper  = VALUES(points_goalkeeper),
                 points_defender    = VALUES(points_defender),
                 points_midfielder  = VALUES(points_midfielder),
                 points_forward     = VALUES(points_forward),
                 invalid          = VALUES(invalid)"
        );

        $checkTx = $this->con_league->prepare(
            "SELECT COUNT(*) FROM transaction
             WHERE team_id = ? AND matchday_id = ? AND reason = 'Spieltagseinnahmen'"
        );
        $insertTx = $this->con_league->prepare(
            "INSERT INTO transaction (id, team_id, amount, reason, matchday_id, created_at)
             VALUES (UUID(), ?, ?, 'Spieltagseinnahmen', ?, DATE_ADD(?, INTERVAL 3 DAY))"
        );

        // formation: [gk, def, mid, fwd]
        $formations = [[1,3,4,3],[1,3,5,2],[1,4,3,3],[1,4,4,2],[1,4,5,1]];

        foreach ($teams as $team) {
            $teamId   = $team['id'];
            $entries  = $lineupByTeam[$teamId] ?? [];
            $nominated = array_values(array_filter($entries, fn($e) => (bool) $e['nominated']));
            $invalid   = empty($nominated) ? 1 : 0;

            $points = $goals = $assists = $redCards = $yellowRedCards = $cleanSheet = $sds = 0;
            $ptsGk  = $ptsDef = $ptsMid = $ptsFwd = $sdsDefender = 0;

            foreach ($nominated as $entry) {
                $pr = $ratingByPlayer[$entry['player_id']] ?? null;
                if (!$pr) continue;
                $points         += (int) $pr['points'];
                $goals          += (int) $pr['goals'];
                $assists        += (int) $pr['assists'];
                $redCards       += (int) $pr['red_card'];
                $yellowRedCards += (int) $pr['yellow_red_card'];
                $cleanSheet     += (int) $pr['clean_sheet'];
                $sds            += (int) $pr['sds'];
                if ($pr['sds'] && in_array($pr['position'], ['GOALKEEPER', 'DEFENDER'])) $sdsDefender++;
                match ($pr['position']) {
                    'GOALKEEPER' => $ptsGk  += (int) $pr['points'],
                    'DEFENDER'   => $ptsDef += (int) $pr['points'],
                    'MIDFIELDER' => $ptsMid += (int) $pr['points'],
                    'FORWARD'    => $ptsFwd += (int) $pr['points'],
                    default      => null,
                };
            }

            if ($invalid) {
                $points = $goals = $assists = $cleanSheet = $sds = 0;
                $ptsGk  = $ptsDef = $ptsMid = $ptsFwd = $sdsDefender = 0;
            }

            $byPos = ['GOALKEEPER' => [], 'DEFENDER' => [], 'MIDFIELDER' => [], 'FORWARD' => []];
            foreach ($entries as $entry) {
                $pr = $ratingByPlayer[$entry['player_id']] ?? null;
                if ($pr && array_key_exists($pr['position'], $byPos)) {
                    $byPos[$pr['position']][] = (int) $pr['points'];
                }
            }
            foreach ($byPos as &$pts) { rsort($pts); }
            unset($pts);

            $maxPoints = 0;
            foreach ($formations as [$gk, $def, $mid, $fwd]) {
                $fp = array_sum(array_slice($byPos['GOALKEEPER'], 0, $gk))
                    + array_sum(array_slice($byPos['DEFENDER'],   0, $def))
                    + array_sum(array_slice($byPos['MIDFIELDER'], 0, $mid))
                    + array_sum(array_slice($byPos['FORWARD'],    0, $fwd));
                if ($fp > $maxPoints) $maxPoints = $fp;
            }

            $insertRating->execute([
                $teamId, $matchdayId,
                $points, $maxPoints, $goals, $assists,
                $redCards, $yellowRedCards, $cleanSheet, $sds, $sdsDefender,
                $ptsGk, $ptsDef, $ptsMid, $ptsFwd, $invalid,
            ]);
            $updatedCount++;

            if ($points > 0) {
                $checkTx->execute([$teamId, $matchdayId]);
                if ((int) $checkTx->fetchColumn() === 0) {
                    $insertTx->execute([$teamId, $points * 20000, $matchdayId, $kickoffDate]);
                }
            }

            try {
                if ($points > 0) {
                    $this->con_old->prepare(
                        "UPDATE team SET budget = budget + ? WHERE team_id = ?"
                    )->execute([$points * 20000, $teamId]);
                }
                $this->con_old->prepare(
                    "UPDATE team_rating
                     SET points            = ?,
                         max_points        = ?,
                         goals             = ?,
                         assists           = ?,
                         clean_sheet       = ?,
                         sds               = ?,
                         sds_defender      = ?,
                         missed_goals      = ?,
                         points_goalkeeper = ?,
                         points_defender   = ?,
                         points_midfielder = ?,
                         points_forward    = ?,
                         invalid           = ?
                     WHERE team_id = ? AND matchday_number = ?"
                )->execute([
                    $points, $maxPoints, $goals, $assists,
                    $cleanSheet, $sds, $sdsDefender, 0,
                    $ptsGk, $ptsDef, $ptsMid, $ptsFwd, $invalid,
                    $teamId, $matchdayNum,
                ]);

                $reward  = number_format($points * 20000, 0, ',', '.');
                $message = 'Dein Team hat <b>' . $points . ' Punkte</b> erzielt. Dafür bekommst du <b>' . $reward . '</b> <i class="fa-solid fa-peseta-sign"></i>.<br><br>'
                    . 'Dein Team hat <b>' . $goals . ' Tore</b> erzielt und <b>' . $assists . ' Vorlagen</b> geliefert.<br>'
                    . 'Mit einer optimalen Aufstellung hättest du <b>' . $maxPoints . ' Punkte</b> erzielt.<br><br>'
                    . '<a href="http://die-bestesten.de/liga/pro/' . $seasonLabel . '/' . $matchdayNum . '">Zur Spieltagstabelle</a>';
                $this->con_old->prepare(
                    "INSERT INTO notification (notification_id, receiver_id, title, message) VALUES (UUID(), ?, ?, ?)"
                )->execute([
                    $team['manager_id'],
                    $matchdayNum . '. Spieltag abgeschlossen!',
                    $message,
                ]);
            } catch (\Throwable $e) {
                error_log('finalizeMatchday old-DB sync failed for team ' . $teamId . ': ' . $e->getMessage());
            }
        }
        return $updatedCount;
    }

    public function sendMatchdayCompletedAdminEmail(string $matchdayId, int $teamRatingsCount, array $newAchievements, int $matchdayNumber): void
    {
        try {
            $adminEmails = $this->con_league->query(
                "SELECT m.email FROM manager m
                 JOIN manager_role mr ON mr.manager_id = m.id
                 WHERE mr.role = 'admin' AND m.email IS NOT NULL AND m.status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (empty($adminEmails)) return;

            $ratingsQ = $this->con_league->prepare(
                "SELECT t.team_name, tr.points, tr.goals, tr.assists,
                        tr.clean_sheet, tr.sds, tr.red_cards, tr.yellow_red_cards, tr.invalid
                 FROM team_rating tr
                 JOIN team t ON t.id = tr.team_id
                 WHERE tr.matchday_id = ?
                 ORDER BY tr.points DESC"
            );
            $ratingsQ->execute([$matchdayId]);
            $teamRatings = $ratingsQ->fetchAll(PDO::FETCH_ASSOC);

            $subject = "$matchdayNumber. Spieltag abgeschlossen — die bestesten";
            $body    = $this->buildMatchdaySummaryEmail($matchdayNumber, $teamRatingsCount, $teamRatings, $newAchievements);
            $headers = "From: noreply@die-bestesten.de\r\nContent-Type: text/html; charset=UTF-8";

            foreach ($adminEmails as $email) {
                mail($email, $subject, $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('sendMatchdayCompletedAdminEmail failed: ' . $e->getMessage());
        }
    }

    private function buildMatchdaySummaryEmail(int $matchdayNumber, int $teamRatingsCount, array $teamRatings, array $newAchievements): string
    {
        $levelLabel        = ['bronze' => 'Bronze', 'silver' => 'Silber', 'gold' => 'Gold'];
        $achievementsCount = count($newAchievements);

        $teamRows = '';
        foreach ($teamRatings as $i => $r) {
            $invalid = (bool) ($r['invalid'] ?? false);
            $pts     = $invalid ? '—' : (int) $r['points'];
            $income  = (!$invalid && (int) $r['points'] > 0)
                ? number_format((int) $r['points'] * 20000, 0, ',', '.') . ' €'
                : '—';
            $cards   = (int) $r['yellow_red_cards'] . 'YR / ' . (int) $r['red_cards'] . 'R';
            $rank    = $i + 1;
            $name    = htmlspecialchars($r['team_name']);
            $style      = $invalid ? ' style="color:#94a3b8;"' : '';
            $sds        = (int) $r['sds'] ?: '';
            $cleanSheet = (int) $r['clean_sheet'] ? '✓' : '';
            $teamRows .= "<tr$style>
                <td style=\"padding:4px 8px;\">$rank</td>
                <td style=\"padding:4px 8px;\">$name</td>
                <td style=\"padding:4px 8px;text-align:center;\">$pts</td>
                <td style=\"padding:4px 8px;text-align:center;\">{$r['goals']}</td>
                <td style=\"padding:4px 8px;text-align:center;\">{$r['assists']}</td>
                <td style=\"padding:4px 8px;text-align:center;\">$cleanSheet</td>
                <td style=\"padding:4px 8px;text-align:center;\">$sds</td>
                <td style=\"padding:4px 8px;text-align:center;\">$cards</td>
                <td style=\"padding:4px 8px;text-align:right;\">$income</td>
            </tr>\n";
        }

        $achRows = '';
        foreach ($newAchievements as $a) {
            $manager = htmlspecialchars($a['manager_name']);
            $achName = htmlspecialchars($a['achievement_name']);
            $level   = htmlspecialchars($levelLabel[$a['level']] ?? $a['level']);
            $reason  = htmlspecialchars($a['reason'] ?? '—');
            $achRows .= "<tr>
                <td style=\"padding:4px 8px;\">$manager</td>
                <td style=\"padding:4px 8px;\">$achName</td>
                <td style=\"padding:4px 8px;\">$level</td>
                <td style=\"padding:4px 8px;color:#64748b;\">$reason</td>
            </tr>\n";
        }

        $achSection = $achievementsCount > 0
            ? "<h3 style=\"margin:24px 0 8px;\">Neue Achievements ($achievementsCount)</h3>
               <table style=\"border-collapse:collapse;width:100%;font-size:13px;\">
                   <thead><tr style=\"background:#1e3a5f;color:#e2e8f0;\">
                       <th style=\"padding:6px 8px;text-align:left;\">Manager</th>
                       <th style=\"padding:6px 8px;text-align:left;\">Achievement</th>
                       <th style=\"padding:6px 8px;text-align:left;\">Level</th>
                       <th style=\"padding:6px 8px;text-align:left;\">Grund</th>
                   </tr></thead>
                   <tbody>$achRows</tbody>
               </table>"
            : "<p style=\"color:#64748b;margin-top:24px;\">Keine neuen Achievements.</p>";

        return "<!DOCTYPE html>
<html lang=\"de\">
<head><meta charset=\"UTF-8\"></head>
<body style=\"font-family:sans-serif;color:#1e293b;background:#f8fafc;padding:24px;max-width:700px;margin:0 auto;\">
    <h2 style=\"margin:0 0 4px;\">$matchdayNumber. Spieltag abgeschlossen</h2>
    <p style=\"color:#64748b;margin:0 0 24px;\">
        <strong>$teamRatingsCount</strong> Team-Ratings erstellt &nbsp;·&nbsp;
        <strong>$achievementsCount</strong> neue Achievements
    </p>
    <h3 style=\"margin:0 0 8px;\">Teams</h3>
    <table style=\"border-collapse:collapse;width:100%;font-size:13px;\">
        <thead><tr style=\"background:#1e3a5f;color:#e2e8f0;\">
            <th style=\"padding:6px 8px;text-align:left;\">#</th>
            <th style=\"padding:6px 8px;text-align:left;\">Team</th>
            <th style=\"padding:6px 8px;text-align:center;\">Pkt</th>
            <th style=\"padding:6px 8px;text-align:center;\">Tore</th>
            <th style=\"padding:6px 8px;text-align:center;\">Vorlagen</th>
            <th style=\"padding:6px 8px;text-align:center;\">CS</th>
            <th style=\"padding:6px 8px;text-align:center;\">SdS</th>
            <th style=\"padding:6px 8px;text-align:center;\">Karten</th>
            <th style=\"padding:6px 8px;text-align:right;\">Einnahmen</th>
        </tr></thead>
        <tbody>$teamRows</tbody>
    </table>
    $achSection
</body>
</html>";
    }

    public function migrateMatchday(): array
    {
        $divisionId = $this->getLeagueDivisionId();
        if (!$divisionId) {
            throw new \RuntimeException('Liga hat keine Division konfiguriert');
        }

        $rows = $this->con_old->query(
            "SELECT matchday_id, season_id, number, start_date, kickoff_date FROM matchday"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->con->prepare(
            "INSERT INTO matchday (id, season_id, division_id, number, start_date, kickoff_date)
             VALUES (:id, :season_id, :division_id, :number, :start_date, :kickoff_date)
             ON DUPLICATE KEY UPDATE
               season_id    = VALUES(season_id),
               division_id  = VALUES(division_id),
               number       = VALUES(number),
               start_date   = VALUES(start_date),
               kickoff_date = VALUES(kickoff_date)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':id'           => $row['matchday_id'],
                ':season_id'    => $row['season_id'],
                ':division_id'  => $divisionId,
                ':number'       => $row['number'],
                ':start_date'   => $row['start_date'],
                ':kickoff_date' => $row['kickoff_date'],
            ]);
        }

        return ['status' => true, 'migrated' => count($rows)];
    }
}
