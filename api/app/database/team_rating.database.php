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
            "SELECT id FROM matchday WHERE season_id = :season_id AND completed = 1"
        );
        $matchdayIds->execute([':season_id' => $seasonId]);
        $ids = array_column($matchdayIds->fetchAll(PDO::FETCH_ASSOC), 'id');

        if (empty($ids)) return [];

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
        return $this->assignFines($rows, 'total_points');
    }

    public function getTeamRatingsByActiveSeason(string $seasonId): array|false
    {
        $mq = $this->con->prepare(
            "SELECT id, number, kickoff_date FROM matchday
             WHERE season_id = :season_id AND completed = 1
             ORDER BY number DESC LIMIT 1"
        );
        $mq->execute([':season_id' => $seasonId]);
        $matchday = $mq->fetch(PDO::FETCH_ASSOC);

        if (!$matchday) return false;

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

        return [
            'matchday'   => $matchday,
            'ratings'    => $ratings,
            'sds_player' => $sdsPlayer,
        ];
    }
}
