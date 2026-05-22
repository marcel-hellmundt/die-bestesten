<?php

trait TeamRatingTrait
{
    private function assignFines(array $rows, string $pointsKey): array
    {
        $hasInvalid = !empty(array_filter($rows, fn($r) => $r['invalid']));
        // If any invalid on this matchday: invalid=3€, valid rank 1→2€/2→1.50€/3→1€
        // Otherwise normal ranking: rank 1→3€/2→2€/3→1.50€/4→1€
        $fineByRank = $hasInvalid ? [1 => 2.0, 2 => 1.5, 3 => 1.0] : [1 => 3.0, 2 => 2.0, 3 => 1.5, 4 => 1.0];

        $validPoints = array_column(array_filter($rows, fn($r) => !$r['invalid']), $pointsKey);
        $uniquePoints = array_unique($validPoints);
        sort($uniquePoints);
        $pointsToFine = [];
        foreach ($uniquePoints as $rank0 => $pts) {
            $pointsToFine[$pts] = $fineByRank[$rank0 + 1] ?? 0.0;
        }

        foreach ($rows as &$row) {
            $row['fine'] = $row['invalid'] ? 3.0 : ($pointsToFine[$row[$pointsKey]] ?? 0.0);
        }
        unset($row);
        return $rows;
    }

    public function getSeasonStandings(string $seasonId): array
    {
        $matchdayIds = $this->con->prepare(
            "SELECT id, number FROM matchday WHERE season_id = :season_id AND completed = 1"
        );
        $matchdayIds->execute([':season_id' => $seasonId]);
        $matchdayRows = $matchdayIds->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_column($matchdayRows, 'id');
        $numberById = array_column($matchdayRows, 'number', 'id');

        if (empty($ids))
            return ['standings' => [], 'luck' => ['lucky' => [], 'unlucky' => []]];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rq = $this->con_league->prepare(
            "SELECT t.id AS team_id, t.team_name, t.color_primary AS color, t.season_id,
                    m.manager_name,
                    COALESCE(SUM(tr.points), 0)             AS total_points,
                    COALESCE(SUM(tr.goals), 0)              AS total_goals,
                    COALESCE(SUM(tr.assists), 0)            AS total_assists,
                    COALESCE(SUM(tr.red_cards), 0)          AS total_red_cards,
                    COALESCE(SUM(tr.yellow_red_cards), 0)   AS total_yellow_red_cards,
                    COALESCE(SUM(tr.sds), 0)                AS total_sds,
                    COALESCE(SUM(tr.sds_defender), 0)       AS total_sds_defender,
                    COALESCE(SUM(tr.clean_sheet), 0)        AS total_clean_sheet,
                    COALESCE(SUM(tr.missed_goals), 0)       AS total_missed_goals,
                    COUNT(CASE WHEN tr.invalid = 0 THEN 1 END) AS matchdays_played
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             JOIN manager m ON m.id = t.manager_id
             WHERE tr.matchday_id IN ($placeholders)
             GROUP BY t.id, t.team_name, t.color_primary, t.season_id, m.manager_name
             ORDER BY total_points DESC"
        );
        $rq->execute($ids);
        $rows = $rq->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['color'] = $this->resolveColor($row['color']); }
        unset($row);

        $luckData = $this->getSeasonLuckStats($ids, $numberById);

        // Build per-team season fine totals and chart series from per-matchday data
        $fineByTeam = [];
        $chartByTeam = [];
        foreach ($luckData['_all'] as $r) {
            $tid = $r['team_id'];
            $fineByTeam[$tid] = ($fineByTeam[$tid] ?? 0.0) + (float) $r['fine'];
            if (!isset($chartByTeam[$tid])) {
                $chartByTeam[$tid] = [
                    'team_id' => $tid,
                    'team_name' => $r['team_name'],
                    'color' => $r['color'],
                    'series' => [],
                ];
            }
            $chartByTeam[$tid]['series'][] = [
                'matchday' => (int) $r['matchday_number'],
                'points' => (int) $r['points'],
            ];
        }
        foreach ($rows as &$row) {
            $row['fine'] = ($fineByTeam[$row['team_id']] ?? 0.0) + 5.0;
        }
        unset($row);

        foreach ($chartByTeam as &$t) {
            usort($t['series'], fn($a, $b) => $a['matchday'] <=> $b['matchday']);
            $cumulative = 0;
            foreach ($t['series'] as &$s) {
                $cumulative += $s['points'];
                $s['points'] = $cumulative;
            }
            unset($s);
        }
        unset($t);

        unset($luckData['_all']);

        return [
            'standings' => $rows,
            'luck' => $luckData,
            'chart' => array_values($chartByTeam),
        ];
    }

    public function getSeasonLuckStats(array $matchdayIds, array $numberById): array
    {
        if (empty($matchdayIds))
            return ['lucky' => [], 'unlucky' => []];

        $placeholders = implode(',', array_fill(0, count($matchdayIds), '?'));

        $rq = $this->con_league->prepare("
            WITH ranked AS (
                SELECT tr.team_id, tr.matchday_id, tr.points, tr.max_points,
                       t.team_name, t.season_id, t.color_primary AS color, m.manager_name, tr.invalid,
                       DENSE_RANK() OVER (PARTITION BY tr.matchday_id, tr.invalid ORDER BY tr.points ASC) AS rank_asc,
                       SUM(tr.invalid)  OVER (PARTITION BY tr.matchday_id)                         AS invalid_cnt
                FROM team_rating tr
                JOIN team t ON t.id = tr.team_id
                JOIN manager m ON m.id = t.manager_id
                WHERE tr.matchday_id IN ($placeholders)
            ),
            with_fines AS (
                SELECT *,
                       CASE
                           WHEN invalid = 1     THEN 3.00
                           WHEN invalid_cnt > 0 THEN
                               CASE WHEN rank_asc = 1 THEN 2.00 WHEN rank_asc = 2 THEN 1.50 WHEN rank_asc = 3 THEN 1.00 ELSE 0 END
                           ELSE
                               CASE WHEN rank_asc = 1 THEN 3.00 WHEN rank_asc = 2 THEN 2.00 WHEN rank_asc = 3 THEN 1.50 WHEN rank_asc = 4 THEN 1.00 ELSE 0 END
                       END AS fine
                FROM ranked
            )
            SELECT * FROM with_fines
        ");
        $rq->execute($matchdayIds);
        $rows = $rq->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['matchday_number'] = $numberById[$row['matchday_id']] ?? null;
            $row['color'] = $this->resolveColor($row['color']);
        }
        unset($row);

        $validRows = array_values(array_filter($rows, fn($r) => !(bool) $r['invalid']));

        // Glückspilze / Pechvögel (per matchday) — valid teams only
        $lucky = array_filter($validRows, fn($r) => (float) $r['fine'] === 0.0);
        $unlucky = array_filter($validRows, fn($r) => (float) $r['fine'] > 0.0);
        usort($lucky, fn($a, $b) => $a['points'] <=> $b['points']);
        usort($unlucky, fn($a, $b) => $b['points'] <=> $a['points']);

        // Goldene Bürste: 3 team_ratings with fewest points in the season — valid teams only
        $sorted = $validRows;
        usort($sorted, fn($a, $b) => $a['points'] <=> $b['points']);
        $goldene_buerste = array_slice($sorted, 0, 3);

        // Hölzerne Bank: 3 teams with largest SUM(max_points - points) — valid teams only
        $gaps = [];
        foreach ($validRows as $r) {
            $tid = $r['team_id'];
            if (!isset($gaps[$tid])) {
                $gaps[$tid] = ['team_id' => $tid, 'team_name' => $r['team_name'], 'manager_name' => $r['manager_name'], 'color' => $r['color'], 'season_id' => $r['season_id'], 'gap' => 0];
            }
            $gaps[$tid]['gap'] += (int) $r['max_points'] - (int) $r['points'];
        }
        usort($gaps, fn($a, $b) => $b['gap'] <=> $a['gap']);
        $hoelzerne_bank = array_slice(array_values($gaps), 0, 3);

        // Spieltagssiege: per matchday, team with highest points wins — valid teams only
        $byMatchday = [];
        foreach ($validRows as $r) {
            $byMatchday[$r['matchday_id']][] = $r;
        }
        $winsByTeam = [];
        foreach ($byMatchday as $mdRows) {
            $maxPts = max(array_column($mdRows, 'points'));
            foreach ($mdRows as $r) {
                if ((int) $r['points'] === (int) $maxPts) {
                    $tid = $r['team_id'];
                    if (!isset($winsByTeam[$tid])) {
                        $winsByTeam[$tid] = ['team_id' => $tid, 'team_name' => $r['team_name'], 'manager_name' => $r['manager_name'], 'wins' => 0];
                    }
                    $winsByTeam[$tid]['wins']++;
                }
            }
        }
        usort($winsByTeam, fn($a, $b) => $b['wins'] <=> $a['wins']);

        return [
            '_all' => $rows,
            'lucky' => array_slice(array_values($lucky), 0, 3),
            'unlucky' => array_slice(array_values($unlucky), 0, 3),
            'goldene_buerste' => $goldene_buerste,
            'hoelzerne_bank' => $hoelzerne_bank,
            'matchday_wins' => array_values($winsByTeam),
        ];
    }

    public function getTeamRatingsByActiveSeason(string $seasonId, ?int $matchdayNumber = null): array|false
    {
        if ($matchdayNumber !== null) {
            $mq = $this->con->prepare(
                "SELECT id, number, kickoff_date, completed FROM matchday
                 WHERE season_id = :season_id AND number = :number
                   AND kickoff_date IS NOT NULL AND kickoff_date <= NOW()
                 LIMIT 1"
            );
            $mq->execute([':season_id' => $seasonId, ':number' => $matchdayNumber]);
        } else {
            // Smallest uncompleted matchday first (= current round);
            // fall back to latest completed if all are done.
            $mq = $this->con->prepare(
                "SELECT id, number, kickoff_date, completed FROM matchday
                 WHERE season_id = :season_id
                 ORDER BY completed ASC, IF(completed = 0, number, -number) ASC
                 LIMIT 1"
            );
            $mq->execute([':season_id' => $seasonId]);
        }
        $matchday = $mq->fetch(PDO::FETCH_ASSOC);

        if (!$matchday)
            return false;

        if ($matchday['completed']) {
            $rq = $this->con_league->prepare(
                "SELECT t.id AS team_id, t.team_name, t.color_primary AS color, t.season_id,
                        m.manager_name, tr.invalid,
                        tr.points, tr.goals, tr.assists, tr.red_cards, tr.yellow_red_cards, tr.sds, tr.clean_sheet
                 FROM team_rating tr
                 JOIN team t ON t.id = tr.team_id
                 JOIN manager m ON m.id = t.manager_id
                 WHERE tr.matchday_id = :matchday_id
                 ORDER BY tr.invalid ASC, tr.points DESC"
            );
            $rq->execute([':matchday_id' => $matchday['id']]);
            $ratings = $rq->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ratings as &$r) { $r['color'] = $this->resolveColor($r['color']); }
            unset($r);
            $ratings = $this->assignFines($ratings, 'points');
        } else {
            $ratings = $this->getLiveTeamRatings($matchday['id']);
        }

        $divisionId = $this->getLeagueDivisionId();
        if ($divisionId !== null) {
            $divisionJoin  = 'JOIN club_in_season cis ON cis.club_id = pr.club_id AND cis.season_id = :season_id
             JOIN division d ON d.id = cis.division_id';
            $divisionWhere = 'AND d.id = :division_id';
            $sdsParams = [':matchday_id' => $matchday['id'], ':season_id' => $seasonId, ':division_id' => $divisionId];
        } else {
            $divisionJoin  = 'JOIN club_in_season cis ON cis.club_id = pr.club_id AND cis.season_id = :season_id
             JOIN division d ON d.id = cis.division_id';
            $divisionWhere = "AND d.level = 1 AND LOWER(d.country_id) = 'de'";
            $sdsParams = [':matchday_id' => $matchday['id'], ':season_id' => $seasonId];
        }

        $sq = $this->con->prepare(
            "SELECT p.id, p.displayname, pis.photo_uploaded, pis.position,
                    pr.points, pr.grade, pr.goals, pr.assists, pr.clean_sheet
             FROM player_rating pr
             JOIN player p ON p.id = pr.player_id
             LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = :season_id
             $divisionJoin
             WHERE pr.matchday_id = :matchday_id $divisionWhere
             ORDER BY pr.points DESC
             LIMIT 1"
        );
        $sq->execute($sdsParams);
        $sdsPlayer = $sq->fetch(PDO::FETCH_ASSOC) ?: null;

        $maxQ = $this->con->prepare(
            "SELECT MAX(number) AS max_number FROM matchday
             WHERE season_id = :season_id
               AND kickoff_date IS NOT NULL AND kickoff_date <= NOW()"
        );
        $maxQ->execute([':season_id' => $seasonId]);
        $maxNumber = (int) $maxQ->fetchColumn();

        return [
            'matchday' => $matchday,
            'ratings' => $ratings,
            'sds_player' => $sdsPlayer,
            'max_matchday_number' => $maxNumber,
        ];
    }

    private function getLiveTeamRatings(string $matchdayId): array
    {
        $lq = $this->con_league->prepare(
            "SELECT tl.team_id, tl.player_id,
                    t.team_name, t.color_primary AS color, t.season_id, m.manager_name
             FROM team_lineup tl
             JOIN team t ON t.id = tl.team_id
             JOIN manager m ON m.id = t.manager_id
             WHERE tl.matchday_id = :matchday_id AND tl.nominated = 1"
        );
        $lq->execute([':matchday_id' => $matchdayId]);
        $lineupRows = $lq->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lineupRows))
            return [];

        $playerIds = array_unique(array_column($lineupRows, 'player_id'));
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));

        $prq = $this->con->prepare(
            "SELECT player_id,
                    COALESCE(points, 0)                                      AS points,
                    COALESCE(goals, 0)                                       AS goals,
                    COALESCE(assists, 0)                                     AS assists,
                    COALESCE(red_card, 0)         AS red_cards,
                    COALESCE(yellow_red_card, 0)  AS yellow_red_cards,
                    COALESCE(clean_sheet, 0)      AS clean_sheet,
                    COALESCE(sds, 0)                                         AS sds
             FROM player_rating
             WHERE matchday_id = ? AND player_id IN ($placeholders)"
        );
        $prq->execute(array_merge([$matchdayId], $playerIds));
        $ratingByPlayer = array_column($prq->fetchAll(PDO::FETCH_ASSOC), null, 'player_id');

        $teamMap = [];
        foreach ($lineupRows as $row) {
            $tid = $row['team_id'];
            if (!isset($teamMap[$tid])) {
                $teamMap[$tid] = [
                    'team_id' => $tid,
                    'team_name' => $row['team_name'],
                    'color' => $this->resolveColor($row['color']),
                    'season_id' => $row['season_id'],
                    'manager_name' => $row['manager_name'],
                    'points' => 0,
                    'goals' => 0,
                    'assists' => 0,
                    'red_cards' => 0,
                    'yellow_red_cards' => 0,
                    'clean_sheet' => 0,
                    'sds' => 0,
                    'fine' => 0.0,
                ];
            }
            $pr = $ratingByPlayer[$row['player_id']] ?? null;
            if ($pr) {
                $teamMap[$tid]['points'] += (int) $pr['points'];
                $teamMap[$tid]['goals'] += (int) $pr['goals'];
                $teamMap[$tid]['assists'] += (int) $pr['assists'];
                $teamMap[$tid]['red_cards'] += (int) $pr['red_cards'];
                $teamMap[$tid]['yellow_red_cards'] += (int) $pr['yellow_red_cards'];
                $teamMap[$tid]['clean_sheet'] += (int) $pr['clean_sheet'];
                $teamMap[$tid]['sds'] += (int) $pr['sds'];
            }
        }

        $ratings = array_values($teamMap);
        usort($ratings, fn($a, $b) => $b['points'] <=> $a['points']);
        return $ratings;
    }
}
