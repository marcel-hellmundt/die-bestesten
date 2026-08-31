<?php

trait PlayerRatingTrait
{
    /**
     * All player_ratings for a matchday, filtered for a club.
     * Returns player base info + position from player_in_season.
     */
    public function getPlayerRatingsByMatchdayAndClub(string $matchdayId, string $clubId): array
    {
        $matchday = $this->getMatchdayById($matchdayId);
        $seasonId = $matchday ? $matchday['season_id'] : null;

        $query = $this->con->prepare("
            SELECT p.id            AS player_id,
                   p.first_name,
                   p.last_name,
                   p.displayname,
                   p.country_id,
                   pis.position,
                   pis.photo_uploaded,
                   pis.price,
                   (SELECT COUNT(*) FROM player_rating pr2
                    JOIN matchday md2 ON md2.id = pr2.matchday_id
                    WHERE pr2.player_id = p.id
                      AND pr2.participation = 'starting'
                      AND md2.season_id = :season_id) AS starting_count,
                   pr.id,
                   pr.club_id,
                   pr.grade,
                   pr.participation,
                   pr.goals,
                   pr.assists,
                   pr.clean_sheet,
                   pr.sds,
                   pr.red_card,
                   pr.yellow_red_card,
                   pr.points
            FROM player_rating pr
            JOIN player p                  ON p.id = pr.player_id
            LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = :season_id
            WHERE pr.matchday_id = :matchday_id
              AND pr.club_id = :club_id
            ORDER BY starting_count DESC, pis.position ASC, pis.price DESC
        ");
        $query->execute([
            ':matchday_id' => $matchdayId,
            ':club_id'     => $clubId,
            ':season_id'   => $seasonId,
        ]);
        $ratings = $query->fetchAll(PDO::FETCH_ASSOC);

        $contributorsByRating = $this->getContributorsForRatings(array_column($ratings, 'id'));

        // Fantasy team (if any) that had this player nominated/benched for this matchday —
        // team_lineup lives in the league DB, player_rating in the global DB, so this is a
        // separate lookup merged in by player_id. Drives the small team-logo hint next to the
        // position badge in the "Punkte eintragen" player list.
        $playerIds  = array_column($ratings, 'player_id');
        $teamByPlayer = [];
        if (!empty($playerIds)) {
            $ph  = implode(',', array_fill(0, count($playerIds), '?'));
            $tlq = $this->con_league->prepare(
                "SELECT tl.player_id, tl.team_id, tl.nominated, t.season_id AS team_season_id
                 FROM team_lineup tl
                 JOIN team t ON t.id = tl.team_id
                 WHERE tl.matchday_id = ? AND tl.player_id IN ($ph)"
            );
            $tlq->execute(array_merge([$matchdayId], $playerIds));
            foreach ($tlq->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $teamByPlayer[$row['player_id']] = $row;
            }
        }

        foreach ($ratings as &$r) {
            $r['contributors'] = $contributorsByRating[$r['id']] ?? [];
            $t = $teamByPlayer[$r['player_id']] ?? null;
            $r['team_id']         = $t['team_id'] ?? null;
            $r['team_season_id']  = $t['team_season_id'] ?? null;
            $r['team_nominated']  = $t ? (bool) $t['nominated'] : false;
        }
        unset($r);

        return $ratings;
    }

    // Wer hat an einer Reihe von player_rating-Zeilen mitgewirkt — gruppiert je Zeile nach
    // Manager (types = alle Kategorien, an denen dieser Manager beteiligt war). Siehe
    // maintainer_contribution in global_schema.sql für das Akkumulations-Modell.
    private function getContributorsForRatings(array $ratingIds): array
    {
        $ratingIds = array_values(array_unique(array_filter($ratingIds)));
        if (empty($ratingIds)) return [];

        $placeholders = implode(',', array_fill(0, count($ratingIds), '?'));
        $q = $this->con->prepare(
            "SELECT mc.player_rating_id, mc.manager_id, m.manager_name, mc.contribution_type
             FROM maintainer_contribution mc
             JOIN manager m ON m.id = mc.manager_id
             WHERE mc.player_rating_id IN ($placeholders)"
        );
        $q->execute($ratingIds);

        $byRating = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $rid = $c['player_rating_id'];
            $mid = $c['manager_id'];
            if (!isset($byRating[$rid][$mid])) {
                $byRating[$rid][$mid] = [
                    'manager_id'   => $mid,
                    'manager_name' => $c['manager_name'],
                    'types'        => [],
                ];
            }
            $byRating[$rid][$mid]['types'][] = $c['contribution_type'];
        }

        return array_map('array_values', $byRating);
    }

    private function insertContribution(string $ratingId, string $managerId, string $type): void
    {
        $this->con->prepare(
            "INSERT IGNORE INTO maintainer_contribution (id, manager_id, player_rating_id, contribution_type)
             VALUES (UUID(), :manager_id, :rating_id, :type)"
        )->execute([':manager_id' => $managerId, ':rating_id' => $ratingId, ':type' => $type]);
    }

    /**
     * Aggregierte Contribution-Übersicht für einen ganzen Spieltag (alle Clubs), sortiert nach
     * Gesamtzahl der bearbeiteten Ratings absteigend — Grundlage für die Sidebar-Zusammenfassung
     * auf /daten/ratings.
     */
    public function getContributionSummaryForMatchday(string $matchdayId): array
    {
        $q = $this->con->prepare(
            "SELECT mc.manager_id, m.manager_name, mc.contribution_type,
                    COUNT(DISTINCT mc.player_rating_id) AS cnt
             FROM maintainer_contribution mc
             JOIN manager m       ON m.id = mc.manager_id
             JOIN player_rating pr ON pr.id = mc.player_rating_id
             WHERE pr.matchday_id = :matchday_id
             GROUP BY mc.manager_id, m.manager_name, mc.contribution_type"
        );
        $q->execute([':matchday_id' => $matchdayId]);

        $byManager = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mid = $row['manager_id'];
            if (!isset($byManager[$mid])) {
                $byManager[$mid] = [
                    'manager_id'   => $mid,
                    'manager_name' => $row['manager_name'],
                    'total'        => 0,
                    'by_type'      => ['create' => 0, 'participation' => 0, 'stats' => 0, 'note' => 0],
                ];
            }
            $cnt = (int) $row['cnt'];
            $byManager[$mid]['by_type'][$row['contribution_type']] = $cnt;
            $byManager[$mid]['total'] += $cnt;
        }

        $result = array_values($byManager);
        usort($result, fn($a, $b) => $b['total'] <=> $a['total']);
        return $result;
    }

    /**
     * Creates empty player_ratings for all current players of a club on a matchday, restricted
     * to players with a valid player_in_season entry for that season (position + price set) —
     * a player_in_club row alone can be stale (e.g. left the club but not yet marked to_date,
     * or simply never added to this season's fantasy pool).
     * Uses INSERT IGNORE so existing ratings are not overwritten.
     * Returns: count of newly created ratings + IDs of existing ones.
     */
    public function initPlayerRatingsForClub(string $matchdayId, string $clubId, string $seasonId, string $managerId): array
    {
        $players = $this->con->prepare(
            "SELECT p.id AS player_id, p.displayname
             FROM player_in_club pic
             JOIN player p ON p.id = pic.player_id
             JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = :season_id
             WHERE pic.club_id = :club_id AND pic.to_date IS NULL
               AND pis.position IS NOT NULL AND pis.price IS NOT NULL AND pis.price > 0
             ORDER BY p.last_name ASC"
        );
        $players->execute([':club_id' => $clubId, ':season_id' => $seasonId]);
        $playerRows = $players->fetchAll(PDO::FETCH_ASSOC);

        $insert = $this->con->prepare(
            "INSERT IGNORE INTO player_rating (id, player_id, matchday_id, club_id)
             VALUES (:id, :player_id, :matchday_id, :club_id)"
        );

        $checkExisting = $this->con->prepare(
            "SELECT id FROM player_rating
             WHERE player_id = :player_id AND matchday_id = :matchday_id
             LIMIT 1"
        );

        $created  = [];
        $existing = [];

        foreach ($playerRows as $row) {
            $checkExisting->execute([
                ':player_id'   => $row['player_id'],
                ':matchday_id' => $matchdayId,
            ]);
            $existingRating = $checkExisting->fetch(PDO::FETCH_ASSOC);

            if ($existingRating) {
                $existing[] = ['player_id' => $row['player_id'], 'displayname' => $row['displayname']];
            } else {
                $newId = $this->generateUUID();
                $insert->execute([
                    ':id'          => $newId,
                    ':player_id'   => $row['player_id'],
                    ':matchday_id' => $matchdayId,
                    ':club_id'     => $clubId,
                ]);
                $this->insertContribution($newId, $managerId, 'create');
                $created[] = ['player_id' => $row['player_id'], 'displayname' => $row['displayname']];
            }
        }

        return [
            'status'   => true,
            'created'  => $created,
            'existing' => $existing,
        ];
    }

    /**
     * Returns per-club rating status for a matchday.
     * [{club_id, rating_count, starter_count, grade_count, red_card_no_grade_count, goals, assists, has_sds}]
     *
     * red_card_no_grade_count: players sent off early enough (straight red) that no grade was
     * ever assignable — grade_count alone would then permanently plateau below 11 even once every
     * player has been fully processed, so callers add this on top of grade_count when checking
     * "is this club done" (e.g. before enabling the CSV Punktecheck).
     */
    public function getClubStatusByMatchday(string $matchdayId): array
    {
        $query = $this->con->prepare("
            SELECT club_id,
                   COUNT(*)                                                     AS rating_count,
                   SUM(participation = 'starting')                              AS starter_count,
                   SUM(grade IS NOT NULL)                                       AS grade_count,
                   SUM(grade IS NULL AND red_card = 1)                          AS red_card_no_grade_count,
                   SUM(goals)                                                   AS goals,
                   SUM(assists)                                                 AS assists,
                   MAX(sds)                                                     AS has_sds
            FROM player_rating
            WHERE matchday_id = :matchday_id
            GROUP BY club_id
        ");
        $query->execute([':matchday_id' => $matchdayId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns season-aggregated player ratings (all matchdays of the same season AND division as
     * the given matchday). CSV-equivalent points = SUM(points) + participation bonus
     * (starting=+2, substitute=+1) + SUM(assists).
     *
     * Scoped to the matchday's division, not just its season: a player who switches divisions
     * mid-season (e.g. promoted/relegated in the winter) keeps all player_rating rows in the same
     * season, but our external CSV provider only counts the points earned in the player's current
     * division — summing across both would double-count a division change as season-wide points.
     */
    public function getPlayerRatingsSummaryBySeason(string $matchdayId): array
    {
        $query = $this->con->prepare("
            SELECT p.kicker_id,
                   p.displayname,
                   SUM(pr.points)   AS db_points,
                   SUM(CASE pr.participation WHEN 'starting' THEN 2 WHEN 'substitute' THEN 1 ELSE 0 END) AS participation_bonus,
                   SUM(pr.assists)  AS total_assists,
                   MAX(CASE WHEN pr.matchday_id = :current_matchday_id THEN pr.club_id END) AS club_id
            FROM player_rating pr
            JOIN player p    ON p.id = pr.player_id
            JOIN matchday md ON md.id = pr.matchday_id
            JOIN matchday cur ON cur.id = :matchday_id
            WHERE md.season_id = cur.season_id AND md.division_id = cur.division_id
            GROUP BY p.id, p.kicker_id, p.displayname
        ");
        $query->execute([':matchday_id' => $matchdayId, ':current_matchday_id' => $matchdayId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExistingKickerIds(array $kickerIds): array
    {
        if (empty($kickerIds)) return [];
        $placeholders = implode(',', array_fill(0, count($kickerIds), '?'));
        $stmt = $this->con->prepare("SELECT kicker_id FROM player WHERE kicker_id IN ($placeholders)");
        $stmt->execute($kickerIds);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'kicker_id');
    }

    /**
     * Returns the best valid XI for a matchday across all 7 formations (self::VALID_FORMATIONS).
     * If $freeAgentsOnly is true, excludes players currently in a fantasy team.
     */
    public function getBestXi(string $matchdayId, bool $freeAgentsOnly = false): array
    {
        $mdQ = $this->con->prepare("SELECT id, season_id FROM matchday WHERE id = ? LIMIT 1");
        $mdQ->execute([$matchdayId]);
        $matchday = $mdQ->fetch(PDO::FETCH_ASSOC);
        if (!$matchday) return ['formation' => null, 'players' => [], 'total_points' => 0];
        $seasonId = $matchday['season_id'];

        $divisionId   = $this->getLeagueDivisionId();
        $divisionJoin = 'JOIN club_in_season cis ON cis.club_id = pr.club_id AND cis.season_id = :season_id
             JOIN division d ON d.id = cis.division_id';
        if ($divisionId !== null) {
            $divisionWhere  = 'AND d.id = :division_id';
            $divisionParams = [':division_id' => $divisionId];
        } else {
            $divisionWhere  = "AND d.level = 1 AND LOWER(d.country_id) = 'de'";
            $divisionParams = [];
        }

        $q = $this->con->prepare("
            SELECT pr.player_id, p.displayname, p.first_name, p.last_name,
                   pis.position, pis.photo_uploaded, pis.price,
                   pr.points, pr.goals, pr.assists, pr.clean_sheet,
                   pr.sds, pr.grade, pr.red_card, pr.yellow_red_card, pr.participation,
                   pr.club_id, c.name AS club_name, c.short_name AS club_short_name,
                   c.logo_uploaded AS club_logo_uploaded
            FROM player_rating pr
            JOIN player p ON p.id = pr.player_id
            LEFT JOIN player_in_season pis ON pis.player_id = pr.player_id AND pis.season_id = :season_id
            LEFT JOIN club c ON c.id = pr.club_id
            $divisionJoin
            WHERE pr.matchday_id = :matchday_id
              AND pis.position IS NOT NULL
              $divisionWhere
        ");
        $q->execute(array_merge(
            [':matchday_id' => $matchdayId, ':season_id' => $seasonId],
            $divisionParams
        ));
        $ratings = $q->fetchAll(PDO::FETCH_ASSOC);

        if ($freeAgentsOnly) {
            $takenQ = $this->con_league->prepare(
                "SELECT pit.player_id FROM player_in_team pit
                 JOIN team t ON t.id = pit.team_id
                 WHERE t.season_id = ? AND pit.to_matchday_id IS NULL"
            );
            $takenQ->execute([$seasonId]);
            $taken   = array_flip($takenQ->fetchAll(PDO::FETCH_COLUMN));
            $ratings = array_values(array_filter($ratings, fn($r) => !isset($taken[$r['player_id']])));
        }

        $byPos = ['GOALKEEPER' => [], 'DEFENDER' => [], 'MIDFIELDER' => [], 'FORWARD' => []];
        foreach ($ratings as $r) {
            $pos = $r['position'] ?? null;
            if (isset($byPos[$pos])) $byPos[$pos][] = $r;
        }
        foreach ($byPos as &$group) {
            usort($group, fn($a, $b) => (int)$b['points'] - (int)$a['points']);
        }
        unset($group);

        $posKeys = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];

        $bestTotal   = -1;
        $bestKey     = null;
        $bestPlayers = [];

        foreach (self::VALID_FORMATIONS as [$gk, $def, $mid, $fwd]) {
            $needs   = [$gk, $def, $mid, $fwd];
            $canFill = true;
            foreach ($posKeys as $i => $pos) {
                if (count($byPos[$pos]) < $needs[$i]) { $canFill = false; break; }
            }
            if (!$canFill) continue;

            $total   = 0;
            $players = [];
            foreach ($posKeys as $i => $pos) {
                $picked   = array_slice($byPos[$pos], 0, $needs[$i]);
                $total   += array_sum(array_column($picked, 'points'));
                $players  = [...$players, ...$picked];
            }
            if ($total > $bestTotal) {
                $bestTotal   = $total;
                $bestKey     = "{$def}{$mid}{$fwd}";
                $bestPlayers = $players;
            }
        }

        // Enrich bestPlayers with team_lineup info (team_id, season_id, nominated)
        if (!empty($bestPlayers)) {
            $playerIds    = array_column($bestPlayers, 'player_id');
            $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
            $lineupQ = $this->con_league->prepare(
                "SELECT tl.player_id, tl.team_id, tl.nominated, t.season_id AS team_season_id
                 FROM team_lineup tl
                 JOIN team t ON t.id = tl.team_id
                 WHERE tl.matchday_id = ? AND tl.player_id IN ($placeholders)"
            );
            $lineupQ->execute([$matchdayId, ...$playerIds]);
            $lineupByPlayer = [];
            foreach ($lineupQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $lineupByPlayer[$row['player_id']] = $row;
            }
            foreach ($bestPlayers as &$player) {
                $entry = $lineupByPlayer[$player['player_id']] ?? null;
                $player['team_id']        = $entry['team_id']        ?? null;
                $player['team_season_id'] = $entry['team_season_id'] ?? null;
                $player['nominated']      = $entry ? (bool) $entry['nominated'] : null;
            }
            unset($player);
        }

        return [
            'formation'    => $bestKey,
            'players'      => $bestPlayers,
            'total_points' => $bestTotal < 0 ? 0 : $bestTotal,
        ];
    }

    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Returns a single player_rating row with player info and position (no starting_count).
     */
    public function getPlayerRatingById(string $id): array|false
    {
        $query = $this->con->prepare("
            SELECT p.id            AS player_id,
                   p.first_name,
                   p.last_name,
                   p.displayname,
                   p.country_id,
                   pis.position,
                   pis.photo_uploaded,
                   pis.price,
                   pr.id,
                   pr.club_id,
                   pr.grade,
                   pr.participation,
                   pr.goals,
                   pr.assists,
                   pr.clean_sheet,
                   pr.sds,
                   pr.red_card,
                   pr.yellow_red_card,
                   pr.points
            FROM player_rating pr
            JOIN player p                  ON p.id = pr.player_id
            JOIN matchday md               ON md.id = pr.matchday_id
            LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = md.season_id
            WHERE pr.id = :id
            LIMIT 1
        ");
        $query->execute([':id' => $id]);
        $rating = $query->fetch(PDO::FETCH_ASSOC);
        if ($rating) {
            $rating['contributors'] = $this->getContributorsForRatings([$id])[$id] ?? [];
        }
        return $rating;
    }

    /**
     * Returns the matchday_id of a player_rating (or null if not found).
     */
    public function getMatchdayIdForRating(string $id): ?string
    {
        $query = $this->con->prepare(
            'SELECT matchday_id FROM player_rating WHERE id = :id LIMIT 1'
        );
        $query->execute([':id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['matchday_id'] : null;
    }

    /**
     * Updates a player_rating row (all editable fields).
     */
    public function updatePlayerRating(string $id, array $data, string $managerId): bool
    {
        $oldSds = null;
        if (array_key_exists('sds', $data) && $data['sds']) {
            $oldRow = $this->con->prepare("SELECT sds, player_id FROM player_rating WHERE id = ? LIMIT 1");
            $oldRow->execute([$id]);
            $oldRow = $oldRow->fetch(PDO::FETCH_ASSOC);
            $oldSds = $oldRow ? (bool) $oldRow['sds'] : null;
            $sdsPlayerId = $oldRow['player_id'] ?? null;
        }

        $allowed = ['grade', 'participation', 'goals', 'assists', 'clean_sheet', 'sds', 'red_card', 'yellow_red_card'];
        $sets    = [];
        $params  = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]           = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($sets)) return false;

        $query = $this->con->prepare(
            'UPDATE player_rating SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $query->execute($params);
        $updated = $query->rowCount() > 0;

        // Always recalculate and persist points
        $newPoints = $this->calculatePoints($id);
        $this->con->prepare('UPDATE player_rating SET points = :p WHERE id = :id')
            ->execute([':p' => $newPoints, ':id' => $id]);

        // Track maintainer contributions (global DB) — accumulate every distinct manager who
        // touched a category, never overwritten (see maintainer_contribution in
        // global_schema.sql), so e.g. two different managers correcting a player's goals both
        // stay credited under 'stats' instead of the later edit erasing the earlier one.
        if (array_key_exists('participation', $data) && $data['participation'] !== null) {
            $this->insertContribution($id, $managerId, 'participation');
        }

        if (array_key_exists('grade', $data) && $data['grade'] !== null) {
            $this->insertContribution($id, $managerId, 'note');
        }

        // Judged on the resulting row, not just the touched fields — if a stat field was patched
        // and the row now has no stats left at all (every one back to 0/false), someone almost
        // certainly just corrected a mistake (e.g. removed a wrongly entered goal) rather than
        // reported anything, so the 'stats' credit no longer applies to anyone and is dropped
        // entirely; resetRating() clearing everything in one call hits this same path.
        $statsFields = ['goals', 'assists', 'clean_sheet', 'sds', 'red_card', 'yellow_red_card'];
        if (array_intersect($statsFields, array_keys($data))) {
            $statsRow = $this->con->prepare(
                'SELECT goals, assists, clean_sheet, sds, red_card, yellow_red_card
                 FROM player_rating WHERE id = :id LIMIT 1'
            );
            $statsRow->execute([':id' => $id]);
            $statsRow = $statsRow->fetch(PDO::FETCH_ASSOC);

            $hasAnyStat = $statsRow && array_reduce(
                $statsFields,
                fn($carry, $field) => $carry || (int) $statsRow[$field] > 0,
                false
            );

            if ($hasAnyStat) {
                $this->insertContribution($id, $managerId, 'stats');
            } else {
                $this->con->prepare(
                    "DELETE FROM maintainer_contribution
                     WHERE player_rating_id = :id AND contribution_type = 'stats'"
                )->execute([':id' => $id]);
            }
        }

        if (isset($oldSds) && $oldSds === false && !empty($sdsPlayerId)) {
            $dnq = $this->con->prepare("SELECT displayname FROM player WHERE id = ? LIMIT 1");
            $dnq->execute([$sdsPlayerId]);
            $displayname = $dnq->fetchColumn() ?: 'Spieler';
            $this->notifyWatchersPlayerSds($sdsPlayerId, $displayname);
        }

        return $updated;
    }

    private function calculatePoints(string $id): int
    {
        $query = $this->con->prepare("
            SELECT pr.grade, pr.participation, pr.goals, pr.assists,
                   pr.clean_sheet, pr.sds, pr.red_card, pr.yellow_red_card,
                   pis.position
            FROM player_rating pr
            JOIN matchday md ON md.id = pr.matchday_id
            LEFT JOIN player_in_season pis ON pis.player_id = pr.player_id AND pis.season_id = md.season_id
            WHERE pr.id = :id
            LIMIT 1
        ");
        $query->execute([':id' => $id]);
        $r = $query->fetch(PDO::FETCH_ASSOC);
        if (!$r) return 0;

        $pos    = $r['position'] ?? null;
        $points = 0;

        if ($r['participation'] === 'starting')      $points += 2;
        elseif ($r['participation'] === 'substitute') $points += 1;

        $goalPts = ['GOALKEEPER' => 6, 'DEFENDER' => 5, 'MIDFIELDER' => 4, 'FORWARD' => 3];
        $points += (int)$r['goals'] * ($goalPts[$pos] ?? 3);

        $points += (int)$r['assists'];

        $points += (int)$r['sds'] * 3;

        if ($pos === 'GOALKEEPER') {
            $points += (int)$r['clean_sheet'] * 2;
        }

        $points -= (int)$r['red_card'] * 6;
        $points -= (int)$r['yellow_red_card'] * 3;

        if ($r['grade'] !== null) {
            $points += (int)round((3.5 - (float)$r['grade']) * 4);
        }

        return $points;
    }
}
