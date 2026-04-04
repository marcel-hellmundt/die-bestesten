<?php

trait PlayerInTeamTrait
{
    public function getSquadByTeamId(string $teamId): array
    {
        // Step 1: get player IDs + season_id from league DB
        $q = $this->con_league->prepare(
            "SELECT pit.player_id, t.season_id
             FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             WHERE pit.team_id = :team_id AND pit.to_matchday_id IS NULL"
        );
        $q->execute([':team_id' => $teamId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) return [];

        $seasonId  = $rows[0]['season_id'];
        $playerIds = array_column($rows, 'player_id');
        $ph        = implode(',', array_fill(0, count($playerIds), '?'));

        // Step 2: get player details + season data + season points from global DB
        $q2 = $this->con->prepare(
            "SELECT p.id, p.displayname, p.country_id,
                    pis.position, pis.price, pis.photo_uploaded,
                    ? AS season_id,
                    COALESCE(SUM(pr.points), 0) AS points
             FROM player p
             LEFT JOIN player_in_season pis
                   ON pis.player_id = p.id AND pis.season_id = ?
             LEFT JOIN player_rating pr
                   ON pr.player_id = p.id
                   AND pr.matchday_id IN (SELECT id FROM matchday WHERE season_id = ?)
             WHERE p.id IN ($ph)
             GROUP BY p.id, p.displayname, p.country_id, pis.position, pis.price, pis.photo_uploaded
             ORDER BY FIELD(pis.position, 'GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'),
                      points DESC,
                      pis.price DESC"
        );
        $q2->execute(array_merge([$seasonId, $seasonId, $seasonId], $playerIds));
        return $q2->fetchAll(PDO::FETCH_ASSOC);
    }
}
