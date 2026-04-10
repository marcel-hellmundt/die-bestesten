<?php

trait TeamLineupTrait
{
    public function getTeamLineup(string $teamId, ?string $matchdayId = null): array|false
    {
        // Get team's season_id
        $teamQ = $this->con_league->prepare("SELECT season_id FROM team WHERE id = :id LIMIT 1");
        $teamQ->execute([':id' => $teamId]);
        $seasonId = $teamQ->fetchColumn();
        if (!$seasonId) return false;

        // Get matchday_ids that have lineup entries for this team (league DB)
        $mdIdsQ = $this->con_league->prepare(
            "SELECT DISTINCT matchday_id FROM team_lineup WHERE team_id = :team_id"
        );
        $mdIdsQ->execute([':team_id' => $teamId]);
        $matchdayIds = $mdIdsQ->fetchAll(PDO::FETCH_COLUMN);

        if (empty($matchdayIds)) {
            return ['matchday' => null, 'matchdays' => [], 'nominated' => [], 'bench' => []];
        }

        // Resolve matchday_ids to number + date (global DB), filter by season
        $ph = implode(',', array_fill(0, count($matchdayIds), '?'));
        $mdListQ = $this->con->prepare(
            "SELECT id, number, kickoff_date
             FROM matchday
             WHERE season_id = ? AND id IN ($ph)
             ORDER BY number ASC"
        );
        $mdListQ->execute(array_merge([$seasonId], $matchdayIds));
        $matchdays = $mdListQ->fetchAll(PDO::FETCH_ASSOC);

        // Resolve target matchday (given or latest)
        if ($matchdayId) {
            $matchday = current(array_filter($matchdays, fn($m) => $m['id'] === $matchdayId)) ?: null;
        } else {
            $matchday = end($matchdays);
        }

        if (!$matchday) return false;

        // Get lineup entries for this matchday
        $lineupQ = $this->con_league->prepare(
            "SELECT player_id, nominated, position_index
             FROM team_lineup
             WHERE team_id = :team_id AND matchday_id = :matchday_id"
        );
        $lineupQ->execute([':team_id' => $teamId, ':matchday_id' => $matchday['id']]);
        $entries = $lineupQ->fetchAll(PDO::FETCH_ASSOC);

        if (empty($entries)) {
            return ['matchday' => $matchday, 'matchdays' => $matchdays, 'nominated' => [], 'bench' => []];
        }

        $playerIds = array_column($entries, 'player_id');
        $ph        = implode(',', array_fill(0, count($playerIds), '?'));

        // Get player details + position from global DB
        $playerQ = $this->con->prepare(
            "SELECT p.id, p.displayname, p.country_id,
                    pis.position, pis.price, pis.photo_uploaded
             FROM player p
             LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = ?
             WHERE p.id IN ($ph)"
        );
        $playerQ->execute(array_merge([$seasonId], $playerIds));
        $playerMap = [];
        foreach ($playerQ->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $playerMap[$p['id']] = $p;
        }

        // Merge lineup meta into player data
        $posOrder = ['GOALKEEPER' => 0, 'DEFENDER' => 1, 'MIDFIELDER' => 2, 'FORWARD' => 3];
        $nominated = [];
        $bench     = [];

        foreach ($entries as $e) {
            $player = $playerMap[$e['player_id']] ?? ['id' => $e['player_id'], 'displayname' => '?', 'position' => null];
            $player['position_index'] = $e['position_index'];
            $player['season_id']      = $seasonId;

            if ($e['nominated']) {
                $nominated[] = $player;
            } else {
                $bench[] = $player;
            }
        }

        $sort = fn($a, $b) =>
            ($posOrder[$a['position'] ?? ''] ?? 9) <=> ($posOrder[$b['position'] ?? ''] ?? 9)
            ?: ($a['position_index'] ?? 99) <=> ($b['position_index'] ?? 99);

        usort($nominated, $sort);
        usort($bench, $sort);

        return [
            'matchday'  => $matchday,
            'matchdays' => $matchdays,
            'nominated' => $nominated,
            'bench'     => $bench,
        ];
    }
}
