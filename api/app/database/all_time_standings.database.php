<?php

trait AllTimeStandingsTrait
{
    public function getAllTimeStandings(): array
    {
        $query = $this->con_league->prepare(
            "SELECT
                m.id,
                m.manager_name,
                m.alias,
                COALESCE(SUM(tr.points), 0) AS total_points
             FROM manager m
             LEFT JOIN team t  ON t.manager_id = m.id
             LEFT JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE m.status != 'deleted'
             GROUP BY m.id, m.manager_name, m.alias
             ORDER BY total_points DESC, m.manager_name ASC"
        );
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
