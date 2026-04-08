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
        $standings = $query->fetchAll(PDO::FETCH_ASSOC);

        // Top 5 best single matchday performances (seasons from 2017/18 onwards)
        $seasonQuery = $this->con->prepare(
            "SELECT id FROM season WHERE start_date >= '2017-07-01'"
        );
        $seasonQuery->execute();
        $validSeasonIds = array_column($seasonQuery->fetchAll(PDO::FETCH_ASSOC), 'id');

        $topMatchdays = [];
        if (!empty($validSeasonIds)) {
            $seasonPlaceholders = implode(',', array_fill(0, count($validSeasonIds), '?'));
            $topQuery = $this->con_league->prepare(
                "SELECT tr.points, tr.matchday_id, t.team_name, t.season_id, m.manager_name
                 FROM team_rating tr
                 JOIN team t ON t.id = tr.team_id
                 JOIN manager m ON m.id = t.manager_id
                 WHERE tr.invalid = 0 AND t.season_id IN ($seasonPlaceholders)
                 ORDER BY tr.points DESC
                 LIMIT 5"
            );
            $topQuery->execute($validSeasonIds);
            $topMatchdays = $topQuery->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($topMatchdays)) {
            $matchdayIds  = array_column($topMatchdays, 'matchday_id');
            $placeholders = implode(',', array_fill(0, count($matchdayIds), '?'));
            $mdQuery = $this->con->prepare(
                "SELECT id, number FROM matchday WHERE id IN ($placeholders)"
            );
            $mdQuery->execute($matchdayIds);
            $mdNumbers = array_column($mdQuery->fetchAll(PDO::FETCH_ASSOC), 'number', 'id');

            foreach ($topMatchdays as &$row) {
                $row['matchday_number'] = $mdNumbers[$row['matchday_id']] ?? null;
            }
            unset($row);
        }

        return [
            'standings'    => $standings,
            'top_matchdays' => $topMatchdays,
        ];
    }
}
