<?php

trait TeamRatingTrait
{
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
                    tr.points, tr.goals, tr.assists, tr.sds
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             JOIN manager m ON m.id = t.manager_id
             WHERE tr.matchday_id = :matchday_id AND tr.invalid = 0
             ORDER BY tr.points DESC"
        );
        $rq->execute([':matchday_id' => $matchday['id']]);

        return [
            'matchday' => $matchday,
            'ratings'  => $rq->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
