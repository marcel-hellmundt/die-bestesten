<?php

trait TeamRatingTrait
{
    private function assignFines(array $rows, string $pointsKey): array
    {
        $fineByRank = [1 => 3.0, 2 => 2.0, 3 => 1.5, 4 => 1.0];

        // Unique point values sorted ASC → lowest = rank 1
        $uniquePoints = array_unique(array_column($rows, $pointsKey));
        sort($uniquePoints);
        $pointsToFine = [];
        foreach ($uniquePoints as $rank0 => $pts) {
            $pointsToFine[$pts] = $fineByRank[$rank0 + 1] ?? 0.0;
        }

        foreach ($rows as &$row) {
            $row['fine'] = $pointsToFine[$row[$pointsKey]] ?? 0.0;
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

        if (empty($ids)) return ['standings' => [], 'luck' => ['lucky' => [], 'unlucky' => []]];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rq = $this->con_league->prepare(
            "SELECT t.id AS team_id, t.team_name, t.color, t.season_id,
                    m.manager_name,
                    SUM(tr.points)      AS total_points,
                    SUM(tr.goals)       AS total_goals,
                    SUM(tr.assists)     AS total_assists,
                    SUM(tr.sds)         AS total_sds,
                    SUM(tr.sds_defender) AS total_sds_defender,
                    SUM(tr.clean_sheet) AS total_clean_sheet,
                    SUM(tr.missed_goals) AS total_missed_goals,
                    COUNT(CASE WHEN tr.invalid = 0 THEN 1 END) AS matchdays_played
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             JOIN manager m ON m.id = t.manager_id
             WHERE tr.matchday_id IN ($placeholders)
             GROUP BY t.id, t.team_name, t.color, t.season_id, m.manager_name
             ORDER BY total_points DESC"
        );
        $rq->execute($ids);
        $rows = $rq->fetchAll(PDO::FETCH_ASSOC);

        $luckData = $this->getSeasonLuckStats($ids, $numberById);

        // Build per-team season fine totals and chart series from per-matchday data
        $fineByTeam  = [];
        $chartByTeam = [];
        foreach ($luckData['_all'] as $r) {
            $tid = $r['team_id'];
            $fineByTeam[$tid] = ($fineByTeam[$tid] ?? 0.0) + (float)$r['fine'];
            if (!isset($chartByTeam[$tid])) {
                $chartByTeam[$tid] = [
                    'team_id'   => $tid,
                    'team_name' => $r['team_name'],
                    'color'     => $r['color'],
                    'series'    => [],
                ];
            }
            $chartByTeam[$tid]['series'][] = [
                'matchday' => (int)$r['matchday_number'],
                'points'   => (int)$r['points'],
            ];
        }
        foreach ($rows as &$row) {
            $row['fine'] = $fineByTeam[$row['team_id']] ?? 0.0;
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
            'luck'      => $luckData,
            'chart'     => array_values($chartByTeam),
        ];
    }

    public function getSeasonLuckStats(array $matchdayIds, array $numberById): array
    {
        if (empty($matchdayIds)) return ['lucky' => [], 'unlucky' => []];

        $placeholders = implode(',', array_fill(0, count($matchdayIds), '?'));

        $rq = $this->con_league->prepare("
            WITH ranked AS (
                SELECT tr.team_id, tr.matchday_id, tr.points, tr.max_points,
                       t.team_name, t.season_id, t.color, m.manager_name,
                       RANK() OVER (PARTITION BY tr.matchday_id ORDER BY tr.points ASC) AS rank_asc
                FROM team_rating tr
                JOIN team t ON t.id = tr.team_id
                JOIN manager m ON m.id = t.manager_id
                WHERE tr.matchday_id IN ($placeholders) AND tr.invalid = 0
            ),
            with_fines AS (
                SELECT *,
                       CASE rank_asc
                           WHEN 1 THEN 3.00 WHEN 2 THEN 2.00
                           WHEN 3 THEN 1.50 WHEN 4 THEN 1.00
                           ELSE 0
                       END AS fine
                FROM ranked
            )
            SELECT * FROM with_fines
        ");
        $rq->execute($matchdayIds);
        $rows = $rq->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['matchday_number'] = $numberById[$row['matchday_id']] ?? null;
        }
        unset($row);

        // Glückspilze / Pechvögel (per matchday)
        $lucky   = array_filter($rows, fn($r) => (float)$r['fine'] === 0.0);
        $unlucky = array_filter($rows, fn($r) => (float)$r['fine'] > 0.0);
        usort($lucky,   fn($a, $b) => $a['points'] <=> $b['points']);
        usort($unlucky, fn($a, $b) => $b['points'] <=> $a['points']);

        // Goldene Bürste: 3 team_ratings with fewest points in the season
        $sorted = $rows;
        usort($sorted, fn($a, $b) => $a['points'] <=> $b['points']);
        $goldene_buerste = array_slice(array_values($sorted), 0, 3);

        // Hölzerne Bank: 3 teams with largest SUM(max_points - points)
        $gaps = [];
        foreach ($rows as $r) {
            $tid = $r['team_id'];
            if (!isset($gaps[$tid])) {
                $gaps[$tid] = ['team_id' => $tid, 'team_name' => $r['team_name'], 'manager_name' => $r['manager_name'], 'color' => $r['color'], 'season_id' => $r['season_id'], 'gap' => 0];
            }
            $gaps[$tid]['gap'] += (int)$r['max_points'] - (int)$r['points'];
        }
        usort($gaps, fn($a, $b) => $b['gap'] <=> $a['gap']);
        $hoelzerne_bank = array_slice(array_values($gaps), 0, 3);

        // Spieltagssiege: per matchday, team with highest points wins
        $byMatchday = [];
        foreach ($rows as $r) {
            $byMatchday[$r['matchday_id']][] = $r;
        }
        $winsByTeam = [];
        foreach ($byMatchday as $mdRows) {
            $maxPts = max(array_column($mdRows, 'points'));
            foreach ($mdRows as $r) {
                if ((int)$r['points'] === (int)$maxPts) {
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
            '_all'             => $rows,
            'lucky'            => array_slice(array_values($lucky),   0, 3),
            'unlucky'          => array_slice(array_values($unlucky),  0, 3),
            'goldene_buerste'  => $goldene_buerste,
            'hoelzerne_bank'   => $hoelzerne_bank,
            'matchday_wins'    => array_values($winsByTeam),
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
            $mq = $this->con->prepare(
                "SELECT id, number, kickoff_date, completed FROM matchday
                 WHERE season_id = :season_id
                   AND kickoff_date IS NOT NULL AND kickoff_date <= NOW()
                 ORDER BY number DESC LIMIT 1"
            );
            $mq->execute([':season_id' => $seasonId]);
        }
        $matchday = $mq->fetch(PDO::FETCH_ASSOC);

        if (!$matchday) return false;

        if ($matchday['completed']) {
            $rq = $this->con_league->prepare(
                "SELECT t.id AS team_id, t.team_name, t.color, t.season_id,
                        m.manager_name,
                        tr.points, tr.goals, tr.assists, tr.sds, tr.clean_sheet
                 FROM team_rating tr
                 JOIN team t ON t.id = tr.team_id
                 JOIN manager m ON m.id = t.manager_id
                 WHERE tr.matchday_id = :matchday_id AND tr.invalid = 0
                 ORDER BY tr.points DESC"
            );
            $rq->execute([':matchday_id' => $matchday['id']]);
            $ratings = $rq->fetchAll(PDO::FETCH_ASSOC);
            $ratings = $this->assignFines($ratings, 'points');
        } else {
            $ratings = $this->getLiveTeamRatings($matchday['id']);
        }

        $sq = $this->con->prepare(
            "SELECT p.id, p.displayname, pis.photo_uploaded, pis.position,
                    pr.points, pr.grade, pr.goals, pr.assists, pr.clean_sheet
             FROM player_rating pr
             JOIN player p ON p.id = pr.player_id
             LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = :season_id
             WHERE pr.matchday_id = :matchday_id AND pr.sds = 1
             LIMIT 1"
        );
        $sq->execute([':matchday_id' => $matchday['id'], ':season_id' => $seasonId]);
        $sdsPlayer = $sq->fetch(PDO::FETCH_ASSOC) ?: null;

        $maxQ = $this->con->prepare(
            "SELECT MAX(number) AS max_number FROM matchday
             WHERE season_id = :season_id
               AND kickoff_date IS NOT NULL AND kickoff_date <= NOW()"
        );
        $maxQ->execute([':season_id' => $seasonId]);
        $maxNumber = (int) $maxQ->fetchColumn();

        return [
            'matchday'            => $matchday,
            'ratings'             => $ratings,
            'sds_player'          => $sdsPlayer,
            'max_matchday_number' => $maxNumber,
        ];
    }

    private function getLiveTeamRatings(string $matchdayId): array
    {
        $lq = $this->con_league->prepare(
            "SELECT tl.team_id, tl.player_id,
                    t.team_name, t.color, t.season_id, m.manager_name
             FROM team_lineup tl
             JOIN team t ON t.id = tl.team_id
             JOIN manager m ON m.id = t.manager_id
             WHERE tl.matchday_id = :matchday_id AND tl.nominated = 1"
        );
        $lq->execute([':matchday_id' => $matchdayId]);
        $lineupRows = $lq->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lineupRows)) return [];

        $playerIds    = array_unique(array_column($lineupRows, 'player_id'));
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));

        $prq = $this->con->prepare(
            "SELECT player_id,
                    COALESCE(points, 0)      AS points,
                    COALESCE(goals, 0)       AS goals,
                    COALESCE(assists, 0)     AS assists,
                    COALESCE(clean_sheet, 0) AS clean_sheet,
                    COALESCE(sds, 0)         AS sds
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
                    'team_id'      => $tid,
                    'team_name'    => $row['team_name'],
                    'color'        => $row['color'],
                    'season_id'    => $row['season_id'],
                    'manager_name' => $row['manager_name'],
                    'points'       => 0,
                    'goals'        => 0,
                    'assists'      => 0,
                    'clean_sheet'  => 0,
                    'sds'          => 0,
                    'fine'         => 0.0,
                ];
            }
            $pr = $ratingByPlayer[$row['player_id']] ?? null;
            if ($pr) {
                $teamMap[$tid]['points']      += (int)$pr['points'];
                $teamMap[$tid]['goals']       += (int)$pr['goals'];
                $teamMap[$tid]['assists']     += (int)$pr['assists'];
                $teamMap[$tid]['clean_sheet'] += (int)$pr['clean_sheet'];
                $teamMap[$tid]['sds']         += (int)$pr['sds'];
            }
        }

        $ratings = array_values($teamMap);
        usort($ratings, fn($a, $b) => $b['points'] <=> $a['points']);
        return $ratings;
    }
}
