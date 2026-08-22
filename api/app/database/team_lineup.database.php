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

        $divisionId = $this->getLeagueDivisionId();

        // When no matchday requested, find current matchday and make sure this team's active
        // squad all have a lineup entry for it (self-healing per team/request — catches players
        // bought after another team's request already triggered the old one-shot init).
        if ($matchdayId === null) {
            $today = date('Y-m-d');

            // matchday.number/start_date are only unique per (season_id, division_id) — each
            // division runs its own schedule. Without scoping to the league's division here,
            // a division with a later-started matchday in the same season would win the
            // ORDER BY, handing this team a matchday_id that belongs to a foreign league.
            if ($divisionId !== null) {
                $curQ = $this->con->prepare(
                    "SELECT id, number FROM matchday
                     WHERE season_id = :sid AND division_id = :did AND start_date <= :today
                     ORDER BY start_date DESC LIMIT 1"
                );
                $curQ->execute([':sid' => $seasonId, ':did' => $divisionId, ':today' => $today]);
            } else {
                $curQ = $this->con->prepare(
                    "SELECT m.id, m.number FROM matchday m
                     JOIN division d ON d.id = m.division_id
                     WHERE m.season_id = :sid AND d.level = 1 AND LOWER(d.country_id) = 'de'
                       AND m.start_date <= :today
                     ORDER BY m.start_date DESC LIMIT 1"
                );
                $curQ->execute([':sid' => $seasonId, ':today' => $today]);
            }
            $currentMatchday = $curQ->fetch(PDO::FETCH_ASSOC);

            if ($currentMatchday) {
                $this->ensureLineupEntriesForTeam(
                    $teamId,
                    $currentMatchday['id'],
                    (int) $currentMatchday['number'],
                    $seasonId
                );
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

        // Resolve matchday_ids to number + date (global DB), filter by season AND division —
        // team_lineup can still hold rows from a foreign division (legacy contamination from
        // before the division scoping above existed, or any other write path); those must never
        // surface as dropdown options here even though the row itself still exists.
        $ph = implode(',', array_fill(0, count($matchdayIds), '?'));
        if ($divisionId !== null) {
            $mdListQ = $this->con->prepare(
                "SELECT id, number, start_date, kickoff_date, completed
                 FROM matchday
                 WHERE season_id = ? AND division_id = ? AND id IN ($ph)
                 ORDER BY number ASC"
            );
            $mdListQ->execute(array_merge([$seasonId, $divisionId], $matchdayIds));
        } else {
            $mdListQ = $this->con->prepare(
                "SELECT m.id, m.number, m.start_date, m.kickoff_date, m.completed
                 FROM matchday m
                 JOIN division d ON d.id = m.division_id
                 WHERE m.season_id = ? AND d.level = 1 AND LOWER(d.country_id) = 'de' AND m.id IN ($ph)
                 ORDER BY m.number ASC"
            );
            $mdListQ->execute(array_merge([$seasonId], $matchdayIds));
        }
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

        // Defensive cleanup: a team_lineup row can outlive the player's active squad membership
        // (e.g. sold via a different transfer window than the one already cleaning up its own
        // matchday in sell.database.php, or any other write path). Only for not-yet-completed
        // matchdays — team_lineup on a completed one is the played historical record and must
        // never be touched, regardless of current ownership.
        if (!$matchday['completed'] && !empty($entries)) {
            $activeQ = $this->con_league->prepare(
                "SELECT player_id FROM player_in_team WHERE team_id = :tid AND to_matchday_id IS NULL"
            );
            $activeQ->execute([':tid' => $teamId]);
            $activePlayerIds = array_flip($activeQ->fetchAll(PDO::FETCH_COLUMN));

            $staleIds = [];
            $entries  = array_values(array_filter($entries, function ($e) use ($activePlayerIds, &$staleIds) {
                if (isset($activePlayerIds[$e['player_id']])) return true;
                $staleIds[] = $e['player_id'];
                return false;
            }));

            if (!empty($staleIds)) {
                $ph = implode(',', array_fill(0, count($staleIds), '?'));
                $this->con_league->prepare(
                    "DELETE FROM team_lineup WHERE team_id = ? AND matchday_id = ? AND player_id IN ($ph)"
                )->execute(array_merge([$teamId, $matchday['id']], $staleIds));
            }
        }

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
            "SELECT player_id, grade, participation, points, goals, assists, clean_sheet, sds, red_card, yellow_red_card
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
            $player['sds']             = (int)($rating['sds'] ?? 0);
            $player['red_card']        = (int)($rating['red_card'] ?? 0);
            $player['yellow_red_card'] = (int)($rating['yellow_red_card'] ?? 0);
            $player['participation']   = $rating['participation'] ?? null;

            if ($e['nominated']) {
                $nominated[] = $player;
            } else {
                $bench[] = $player;
            }
        }

        $sort = fn($a, $b) =>
            ($posOrder[$a['position'] ?? ''] ?? 9) <=> ($posOrder[$b['position'] ?? ''] ?? 9)
            ?: ($a['position_index'] ?? 99) <=> ($b['position_index'] ?? 99);

        // Sanity check: if the nominated formation is no longer reachable by any valid shape
        // (legacy corrupted data, a race condition, or a bug that slipped an invalid save past
        // updateTeamLineup() before this check existed), reset it back to the bench rather than
        // serving/scoring an impossible lineup. Never for completed matchdays (historical record,
        // same guard as the stale-player cleanup above).
        if (!$matchday['completed'] && !empty($nominated)) {
            $counts = ['GOALKEEPER' => 0, 'DEFENDER' => 0, 'MIDFIELDER' => 0, 'FORWARD' => 0];
            foreach ($nominated as $p) {
                if (isset($counts[$p['position']])) $counts[$p['position']]++;
            }
            if (!$this->isReachableFormation($counts)) {
                $this->con_league->prepare(
                    "UPDATE team_lineup SET nominated = 0, position_index = NULL
                     WHERE team_id = :tid AND matchday_id = :mid"
                )->execute([':tid' => $teamId, ':mid' => $matchday['id']]);

                foreach ($nominated as &$p) {
                    $p['position_index'] = null;
                }
                unset($p);
                $bench     = array_merge($bench, $nominated);
                $nominated = [];
            }
        }

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

    // Applies the submitted nominated/position_index changes and validates the resulting
    // formation before committing — never persists a formation that no valid shape can reach
    // (see isReachableFormation()). Returns ['ok' => true] or ['ok' => false, 'formation' => counts].
    public function updateTeamLineup(string $teamId, string $matchdayId, array $players): array
    {
        $this->con_league->beginTransaction();

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

        $counts = $this->getNominatedFormationCounts($teamId, $matchdayId);
        if (!$this->isReachableFormation($counts)) {
            $this->con_league->rollBack();
            return ['ok' => false, 'formation' => $counts];
        }

        $this->con_league->commit();
        return ['ok' => true];
    }

    // Counts currently nominated=1 players per position for a team's lineup on a matchday.
    private function getNominatedFormationCounts(string $teamId, string $matchdayId): array
    {
        $counts = ['GOALKEEPER' => 0, 'DEFENDER' => 0, 'MIDFIELDER' => 0, 'FORWARD' => 0];

        $seasonQ = $this->con_league->prepare("SELECT season_id FROM team WHERE id = :id LIMIT 1");
        $seasonQ->execute([':id' => $teamId]);
        $seasonId = $seasonQ->fetchColumn();
        if (!$seasonId) return $counts;

        $playerQ = $this->con_league->prepare(
            "SELECT player_id FROM team_lineup
             WHERE team_id = :tid AND matchday_id = :mid AND nominated = 1"
        );
        $playerQ->execute([':tid' => $teamId, ':mid' => $matchdayId]);
        $playerIds = $playerQ->fetchAll(PDO::FETCH_COLUMN);
        if (empty($playerIds)) return $counts;

        $ph = implode(',', array_fill(0, count($playerIds), '?'));
        $posQ = $this->con->prepare(
            "SELECT position, COUNT(*) AS c FROM player_in_season
             WHERE season_id = ? AND player_id IN ($ph) GROUP BY position"
        );
        $posQ->execute(array_merge([$seasonId], $playerIds));
        foreach ($posQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($counts[$row['position']])) $counts[$row['position']] = (int) $row['c'];
        }
        return $counts;
    }

    // True if at least one of the 7 valid formations dominates the given per-position counts
    // in every position — i.e. the counts are still reachable towards a valid full XI. This is
    // intentionally more permissive than "exactly one of the 7 formations" so an in-progress,
    // partially built lineup (live-saved after every drag) isn't rejected mid-build; it only
    // rejects states that are already impossible (a position count beyond any formation's max).
    private function isReachableFormation(array $counts): bool
    {
        foreach (self::VALID_FORMATIONS as [$gk, $def, $mid, $fwd]) {
            if (
                $gk  >= $counts['GOALKEEPER'] &&
                $def >= $counts['DEFENDER']   &&
                $mid >= $counts['MIDFIELDER'] &&
                $fwd >= $counts['FORWARD']
            ) {
                return true;
            }
        }
        return false;
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

    // Idempotent: gives every active-squad player of this team a team_lineup row for the given
    // matchday if they don't already have one (e.g. bought after the matchday's initial init, or
    // the team's very first load for this matchday). Carried-over players keep their previous
    // nominated/position_index; players with no previous entry (new buys) start on the bench.
    private function ensureLineupEntriesForTeam(string $teamId, string $matchdayId, int $matchdayNumber, string $seasonId): void
    {
        $activeQ = $this->con_league->prepare(
            "SELECT player_id FROM player_in_team WHERE team_id = :tid AND to_matchday_id IS NULL"
        );
        $activeQ->execute([':tid' => $teamId]);
        $activePlayers = $activeQ->fetchAll(PDO::FETCH_COLUMN);

        if (empty($activePlayers)) return;

        $existingQ = $this->con_league->prepare(
            "SELECT player_id FROM team_lineup WHERE team_id = :tid AND matchday_id = :mid"
        );
        $existingQ->execute([':tid' => $teamId, ':mid' => $matchdayId]);
        $existing = array_flip($existingQ->fetchAll(PDO::FETCH_COLUMN));

        $missing = array_filter($activePlayers, fn($pid) => !isset($existing[$pid]));
        if (empty($missing)) return;

        // Scope to the same division as $matchdayId itself (not just season_id) — each division
        // runs its own matchday numbering, so an unscoped lookup could pick a "number < X" match
        // from a foreign division sharing this season.
        $divisionQ = $this->con->prepare("SELECT division_id FROM matchday WHERE id = :id LIMIT 1");
        $divisionQ->execute([':id' => $matchdayId]);
        $divisionId = $divisionQ->fetchColumn();

        $prevQ = $this->con->prepare(
            "SELECT id FROM matchday
             WHERE season_id = :sid AND division_id = :did AND number < :num
             ORDER BY number DESC LIMIT 1"
        );
        $prevQ->execute([':sid' => $seasonId, ':did' => $divisionId, ':num' => $matchdayNumber]);
        $prevMatchdayId = $prevQ->fetchColumn() ?: null;

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

        $insertQ = $this->con_league->prepare(
            "INSERT IGNORE INTO team_lineup (id, team_id, player_id, matchday_id, nominated, position_index)
             VALUES (UUID(), :tid, :pid, :mid, :nom, :pidx)"
        );
        foreach ($missing as $playerId) {
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
