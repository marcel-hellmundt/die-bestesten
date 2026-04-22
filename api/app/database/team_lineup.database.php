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

        // When no matchday requested, find current matchday and auto-init lineup if needed
        if ($matchdayId === null) {
            $today = date('Y-m-d');
            $curQ  = $this->con->prepare(
                "SELECT id, number FROM matchday
                 WHERE season_id = :sid AND start_date <= :today
                 ORDER BY start_date DESC LIMIT 1"
            );
            $curQ->execute([':sid' => $seasonId, ':today' => $today]);
            $currentMatchday = $curQ->fetch(PDO::FETCH_ASSOC);

            if ($currentMatchday) {
                $existsQ = $this->con_league->prepare(
                    "SELECT COUNT(*) FROM team_lineup WHERE matchday_id = :mid"
                );
                $existsQ->execute([':mid' => $currentMatchday['id']]);
                if ($existsQ->fetchColumn() == 0) {
                    $this->initLineupForMatchday(
                        $currentMatchday['id'],
                        (int) $currentMatchday['number'],
                        $seasonId
                    );
                }
            }
        }

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
            "SELECT id, number, start_date, kickoff_date
             FROM matchday
             WHERE season_id = ? AND id IN ($ph)
             ORDER BY number ASC"
        );
        $mdListQ->execute(array_merge([$seasonId], $matchdayIds));
        $matchdays = $mdListQ->fetchAll(PDO::FETCH_ASSOC);

        // Resolve target matchday (given or current by start_date)
        if ($matchdayId) {
            $matchday = current(array_filter($matchdays, fn($m) => $m['id'] === $matchdayId)) ?: null;
        } else {
            $today    = date('Y-m-d');
            $matchday = null;
            foreach ($matchdays as $m) {
                if ($m['start_date'] <= $today) $matchday = $m;
            }
            if (!$matchday) $matchday = $matchdays[0];
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

        // Get player ratings for this matchday from global DB
        $ratingQ = $this->con->prepare(
            "SELECT player_id, grade, participation, points, goals, assists, clean_sheet, sds
             FROM player_rating
             WHERE matchday_id = ? AND player_id IN ($ph)"
        );
        $ratingQ->execute(array_merge([$matchday['id']], $playerIds));
        $ratingMap = [];
        foreach ($ratingQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ratingMap[$r['player_id']] = $r;
        }

        // Merge lineup meta + ratings into player data
        $posOrder = ['GOALKEEPER' => 0, 'DEFENDER' => 1, 'MIDFIELDER' => 2, 'FORWARD' => 3];
        $nominated = [];
        $bench     = [];

        foreach ($entries as $e) {
            $player  = $playerMap[$e['player_id']] ?? ['id' => $e['player_id'], 'displayname' => '?', 'position' => null];
            $rating  = $ratingMap[$e['player_id']] ?? [];
            $player['position_index'] = $e['position_index'];
            $player['season_id']      = $seasonId;
            $player['grade']          = $rating['grade'] ?? null;
            $player['points']         = isset($rating['points']) ? (int)$rating['points'] : null;
            $player['goals']          = (int)($rating['goals'] ?? 0);
            $player['assists']        = (int)($rating['assists'] ?? 0);
            $player['clean_sheet']    = (int)($rating['clean_sheet'] ?? 0);
            $player['sds']            = (int)($rating['sds'] ?? 0);
            $player['participation']  = $rating['participation'] ?? null;

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

        $nominatedPoints = array_sum(array_map(fn($p) => $p['points'] ?? 0, $nominated));
        $maxPoints       = array_sum(array_map(fn($p) => $p['points'] ?? 0, array_merge($nominated, $bench)));

        return [
            'matchday'   => $matchday,
            'matchdays'  => $matchdays,
            'nominated'  => $nominated,
            'bench'      => $bench,
            'points'     => $nominatedPoints,
            'max_points' => $maxPoints,
        ];
    }

    public function getPlayerLineup(string $playerId, string $seasonId): array
    {
        $mq = $this->con->prepare("SELECT id, number FROM matchday WHERE season_id = ?");
        $mq->execute([$seasonId]);
        $matchdays = $mq->fetchAll(PDO::FETCH_ASSOC);
        $mdMap = [];
        foreach ($matchdays as $m) $mdMap[$m['id']] = (int) $m['number'];
        $matchdayIds = array_keys($mdMap);
        if (empty($matchdayIds)) return [];

        $ph = implode(',', array_fill(0, count($matchdayIds), '?'));
        $lq = $this->con_league->prepare(
            "SELECT matchday_id, nominated FROM team_lineup
             WHERE player_id = ? AND matchday_id IN ($ph)"
        );
        $lq->execute(array_merge([$playerId], $matchdayIds));
        $rows = $lq->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            if (!isset($mdMap[$r['matchday_id']])) continue;
            $result[] = [
                'matchday_number' => $mdMap[$r['matchday_id']],
                'nominated'       => (bool) $r['nominated'],
            ];
        }
        return $result;
    }

    public function updateTeamLineup(string $teamId, string $matchdayId, array $players): bool
    {
        $stmt = $this->con_league->prepare(
            "UPDATE team_lineup SET nominated = :nom, position_index = :pidx
             WHERE team_id = :tid AND matchday_id = :mid AND player_id = :pid"
        );
        foreach ($players as $p) {
            $stmt->execute([
                ':nom'  => empty($p['nominated']) ? 0 : 1,
                ':pidx' => $p['position_index'] ?? null,
                ':tid'  => $teamId,
                ':mid'  => $matchdayId,
                ':pid'  => $p['player_id'],
            ]);
        }
        return true;
    }

    public function getTeamOwner(string $teamId): ?string
    {
        $q = $this->con_league->prepare("SELECT manager_id FROM team WHERE id = :id LIMIT 1");
        $q->execute([':id' => $teamId]);
        return $q->fetchColumn() ?: null;
    }

    public function isMatchdayOpen(string $matchdayId): bool
    {
        $q = $this->con->prepare(
            "SELECT id FROM matchday
             WHERE id = :id AND start_date <= CURDATE() AND kickoff_date > NOW() LIMIT 1"
        );
        $q->execute([':id' => $matchdayId]);
        return (bool) $q->fetchColumn();
    }

    private function initLineupForMatchday(string $matchdayId, int $matchdayNumber, string $seasonId): void
    {
        // Find previous matchday in global DB
        $prevQ = $this->con->prepare(
            "SELECT id FROM matchday
             WHERE season_id = :sid AND number < :num
             ORDER BY number DESC LIMIT 1"
        );
        $prevQ->execute([':sid' => $seasonId, ':num' => $matchdayNumber]);
        $prevMatchdayId = $prevQ->fetchColumn() ?: null;

        // Get all teams for this season
        $teamsQ = $this->con_league->prepare("SELECT id FROM team WHERE season_id = :sid");
        $teamsQ->execute([':sid' => $seasonId]);
        $teamIds = $teamsQ->fetchAll(PDO::FETCH_COLUMN);

        if (empty($teamIds)) return;

        $insertQ = $this->con_league->prepare(
            "INSERT IGNORE INTO team_lineup (id, team_id, player_id, matchday_id, nominated, position_index)
             VALUES (UUID(), :tid, :pid, :mid, :nom, :pidx)"
        );

        foreach ($teamIds as $teamId) {
            // Active squad players
            $activeQ = $this->con_league->prepare(
                "SELECT player_id FROM player_in_team WHERE team_id = :tid AND to_matchday_id IS NULL"
            );
            $activeQ->execute([':tid' => $teamId]);
            $activePlayers = $activeQ->fetchAll(PDO::FETCH_COLUMN);

            if (empty($activePlayers)) continue;

            // Previous lineup for this team (nominated + position_index)
            $prevLineup = [];
            if ($prevMatchdayId) {
                $prevLQ = $this->con_league->prepare(
                    "SELECT player_id, nominated, position_index
                     FROM team_lineup WHERE team_id = :tid AND matchday_id = :mid"
                );
                $prevLQ->execute([':tid' => $teamId, ':mid' => $prevMatchdayId]);
                foreach ($prevLQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $prevLineup[$row['player_id']] = $row;
                }
            }

            foreach ($activePlayers as $playerId) {
                $prev = $prevLineup[$playerId] ?? null;
                $insertQ->execute([
                    ':tid'  => $teamId,
                    ':pid'  => $playerId,
                    ':mid'  => $matchdayId,
                    ':nom'  => $prev ? (int) $prev['nominated'] : 0,
                    ':pidx' => $prev ? $prev['position_index'] : null,
                ]);
            }
        }
    }
}
