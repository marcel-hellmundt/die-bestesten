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
                COALESCE(SUM(tr.points), 0) AS total_points,
                COUNT(DISTINCT t.season_id) AS seasons_played,
                COUNT(CASE WHEN tr.id IS NOT NULL AND tr.invalid = 0 THEN 1 END) AS matchdays_played
             FROM manager m
             INNER JOIN team t  ON t.manager_id = m.id
             LEFT JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE m.status != 'deleted'
             GROUP BY m.id, m.manager_name, m.alias
             ORDER BY total_points DESC, m.manager_name ASC"
        );
        $query->execute();
        $standings = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($standings as &$row) {
            $row['seasons_played']       = (int) $row['seasons_played'];
            $row['matchdays_played']     = (int) $row['matchdays_played'];
            $row['points_per_matchday']  = $row['matchdays_played'] > 0
                ? round((float) $row['total_points'] / $row['matchdays_played'], 2)
                : 0.0;
        }
        unset($row);

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

            // Saisons, in denen diese Liga gar nicht gespielt hat (keine Teams), sollen im
            // Bewegungs-Grid nicht als leere Spalte auftauchen — cumulative[] wurde oben ohnehin
            // nicht verändert, das Überspringen hier wirkt sich also nur auf die Anzeige aus.
            if (empty($entries)) continue;

            $result[] = [
                'season_id' => $season['id'],
                'entries'   => $entries,
            ];
        }

        return $result;
    }

    /**
     * Für jeden Tabellenplatz 1..12 (feste 12er-Liga) das beste und schlechteste jemals dort
     * erzielte Saisonergebnis — nur Saisons mit genau 12 teilnehmenden Teams zählen (ein Team
     * weniger/mehr würde die Platzierungen verzerren), die aktuelle (laufende) Saison wird
     * ausgeschlossen (deren Punktestand ist noch unfertig und würde den "schlechtesten" Platz zu
     * Saisonbeginn systematisch belegen). Fürs Ruhmeshalle-"Bestes/Schlechtestes Ergebnis je
     * Platz"-Grid (webapp: HallOfFameComponent). Gibt [] zurück, wenn die aktuelle Saison dieser
     * Liga nicht (mehr) 12 Teams hat — die App unterstützt auch andere Ligagrößen (siehe H2H:
     * 9- oder 12-Team-Format), das Feature ist aber auf die feste 12er-Liga zugeschnitten.
     */
    public function getAllTimeStandingsByPosition(): array
    {
        $teamCount = 12;
        $activeSeasonId = $this->getActiveSeasonId();
        if ($activeSeasonId === null) return [];

        $activeTeamCountQ = $this->con_league->prepare(
            "SELECT COUNT(*) FROM team WHERE season_id = ?"
        );
        $activeTeamCountQ->execute([$activeSeasonId]);
        if ((int) $activeTeamCountQ->fetchColumn() !== $teamCount) return [];

        $q = $this->con_league->prepare(
            "SELECT t.season_id, t.id AS team_id, t.team_name,
                    t.color_primary AS color, t.color_secondary,
                    COALESCE(SUM(tr.points), 0) AS total_points
             FROM team t
             LEFT JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             GROUP BY t.season_id, t.id, t.team_name, t.color_primary, t.color_secondary"
        );
        $q->execute();

        $bySeason = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['season_id'] === $activeSeasonId) continue;
            $bySeason[$r['season_id']][] = $r;
        }

        $seasonLabels = [];
        if (!empty($bySeason)) {
            $seasonIds = array_keys($bySeason);
            $ph = implode(',', array_fill(0, count($seasonIds), '?'));
            $sq = $this->con->prepare("SELECT id, start_date FROM season WHERE id IN ($ph)");
            $sq->execute($seasonIds);
            foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $year = (int) substr($s['start_date'], 0, 4);
                $y1   = str_pad($year % 100, 2, '0', STR_PAD_LEFT);
                $y2   = str_pad(($year + 1) % 100, 2, '0', STR_PAD_LEFT);
                $seasonLabels[$s['id']] = "$y1/$y2";
            }
        }

        $best      = [];
        $worst     = [];
        $sumPoints = [];
        $count     = [];

        foreach ($bySeason as $seasonId => $teams) {
            if (count($teams) !== $teamCount) continue;

            usort($teams, fn($a, $b) => (float) $b['total_points'] <=> (float) $a['total_points']
                ?: strcmp($a['team_name'], $b['team_name']));

            $prevPoints = null;
            $prevRank   = 0;
            foreach ($teams as $i => $t) {
                $points = (float) $t['total_points'];
                $rank   = ($prevPoints !== null && $points === $prevPoints) ? $prevRank : $i + 1;
                $prevRank   = $rank;
                $prevPoints = $points;

                $entry = [
                    'team_id'         => $t['team_id'],
                    'team_name'       => $t['team_name'],
                    'color'           => $this->resolveColor($t['color']),
                    'color_secondary' => $this->resolveColor($t['color_secondary']),
                    'points'          => $points,
                    'season_id'       => $seasonId,
                    'season_label'    => $seasonLabels[$seasonId] ?? null,
                ];

                if (!isset($best[$rank]) || $points > $best[$rank]['points']) {
                    $best[$rank] = $entry;
                }
                if (!isset($worst[$rank]) || $points < $worst[$rank]['points']) {
                    $worst[$rank] = $entry;
                }

                $sumPoints[$rank] = ($sumPoints[$rank] ?? 0) + $points;
                $count[$rank]     = ($count[$rank] ?? 0) + 1;
            }
        }

        $result = [];
        for ($pos = 1; $pos <= $teamCount; $pos++) {
            $result[] = [
                'position'      => $pos,
                'best'          => $best[$pos]  ?? null,
                'worst'         => $worst[$pos] ?? null,
                'average_points' => isset($count[$pos]) ? round($sumPoints[$pos] / $count[$pos], 1) : null,
            ];
        }
        return $result;
    }
}
