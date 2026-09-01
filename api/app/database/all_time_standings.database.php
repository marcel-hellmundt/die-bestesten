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
             INNER JOIN team t  ON t.manager_id = m.id
             LEFT JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE m.status != 'deleted'
             GROUP BY m.id, m.manager_name, m.alias
             ORDER BY total_points DESC, m.manager_name ASC"
        );
        $query->execute();
        $standings = $query->fetchAll(PDO::FETCH_ASSOC);

        // Top 5 best single matchday performances (seasons from 2017/18 onwards)
        $seasonQuery = $this->con->prepare(
            "SELECT id FROM season WHERE start_date >= '" . self::STATS_SEASON_START . "'"
        );
        $seasonQuery->execute();
        $validSeasonIds = array_column($seasonQuery->fetchAll(PDO::FETCH_ASSOC), 'id');

        $topMatchdays = [];
        if (!empty($validSeasonIds)) {
            $seasonPlaceholders = implode(',', array_fill(0, count($validSeasonIds), '?'));
            $topQuery = $this->con_league->prepare(
                "SELECT tr.points, tr.matchday_id, t.id AS team_id, t.team_name, t.season_id, m.id AS manager_id, m.manager_name
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
            $matchdayIds = array_column($topMatchdays, 'matchday_id');
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
            'standings' => $standings,
            'top_matchdays' => $topMatchdays,
        ];
    }

    /**
     * Für jede Saison (chronologisch) der Rang jedes teilnehmenden Managers in der ewigen
     * Tabelle NACH dieser Saison — kumulierte Punkte über alle Saisons bis einschließlich dieser
     * (nicht nur die Saisonpunkte selbst) — plus der kumulierten Punktesumme für den Tooltip.
     * Fürs Ruhmeshalle-Bewegungs-Grid (webapp: HallOfFameComponent). Rang = Position unter ALLEN
     * Managern mit je Cent Punkten bis zu diesem Zeitpunkt (Standard-Wettkampf-Rang, punktgleiche
     * Manager teilen sich denselben Platz), nicht nur unter den Teilnehmern dieser einen Saison —
     * ein Manager, der diese Saison pausiert, bleibt also im Nenner für alle anderen relevant.
     * Nur Manager mit einem Team in der jeweiligen Saison tauchen in deren entries[] auf.
     */
    public function getAllTimeStandingsBySeason(): array
    {
        $seasonQuery = $this->con->query("SELECT id, start_date FROM season ORDER BY start_date ASC");
        $seasons = $seasonQuery->fetchAll(PDO::FETCH_ASSOC);
        if (empty($seasons)) return [];

        $managerQuery = $this->con_league->query(
            "SELECT id, manager_name FROM manager WHERE status != 'deleted'"
        );
        $managers = array_column($managerQuery->fetchAll(PDO::FETCH_ASSOC), null, 'id');

        $pointsQuery = $this->con_league->query(
            "SELECT t.season_id, t.manager_id, COALESCE(SUM(tr.points), 0) AS season_points
             FROM team t
             LEFT JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             GROUP BY t.season_id, t.manager_id"
        );
        $bySeason = [];
        foreach ($pointsQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bySeason[$row['season_id']][$row['manager_id']] = (float) $row['season_points'];
        }

        $cumulative = [];
        $result     = [];

        foreach ($seasons as $season) {
            $participants = $bySeason[$season['id']] ?? [];
            foreach ($participants as $managerId => $points) {
                if (!isset($managers[$managerId])) continue; // deleted manager
                $cumulative[$managerId] = ($cumulative[$managerId] ?? 0) + $points;
            }

            $ranked = [];
            foreach ($cumulative as $managerId => $total) {
                $ranked[] = ['manager_id' => $managerId, 'total' => $total];
            }
            usort($ranked, fn($a, $b) => $b['total'] <=> $a['total']
                ?: strcmp($managers[$a['manager_id']]['manager_name'], $managers[$b['manager_id']]['manager_name']));

            $rankByManager = [];
            $prevTotal     = null;
            $prevRank      = 0;
            foreach ($ranked as $i => $r) {
                $rank = ($prevTotal !== null && $r['total'] === $prevTotal) ? $prevRank : $i + 1;
                $rankByManager[$r['manager_id']] = $rank;
                $prevRank  = $rank;
                $prevTotal = $r['total'];
            }

            $entries = [];
            foreach ($participants as $managerId => $points) {
                if (!isset($managers[$managerId])) continue;
                $entries[] = [
                    'manager_id'        => $managerId,
                    'manager_name'      => $managers[$managerId]['manager_name'],
                    'rank'              => $rankByManager[$managerId],
                    'cumulative_points' => $cumulative[$managerId],
                ];
            }
            usort($entries, fn($a, $b) => $a['rank'] <=> $b['rank']);

            $result[] = [
                'season_id' => $season['id'],
                'entries'   => $entries,
            ];
        }

        return $result;
    }
}
