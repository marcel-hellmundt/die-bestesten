<?php

trait PlayerInSeasonTrait
{
    /**
     * All league players not currently in any fantasy team — usable as a "free agent market".
     * Returns player info, position, price, cumulative season points, and club data.
     */
    public function getAvailablePlayers(?string $seasonId): array
    {
        if (!$seasonId) {
            $seasonId = $this->getActiveSeasonId();
            if (!$seasonId) return ['players' => []];
        }

        // Get player IDs already in a fantasy team this season (league DB)
        $excludedIds = [];
        try {
            $ex = $this->con_league->prepare(
                "SELECT DISTINCT pit.player_id
                 FROM player_in_team pit
                 JOIN team t ON t.id = pit.team_id
                 WHERE t.season_id = ? AND pit.to_matchday_id IS NULL"
            );
            $ex->execute([$seasonId]);
            $excludedIds = $ex->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {}

        $exclusionClause  = '';
        $exclusionParams  = [];
        if (!empty($excludedIds)) {
            $ph              = implode(',', array_fill(0, count($excludedIds), '?'));
            $exclusionClause = "AND p.id NOT IN ($ph)";
            $exclusionParams = $excludedIds;
        }

        // Division filter: use configured league division or fall back to level 1 / DE
        $divisionId = $this->getLeagueDivisionId();
        if ($divisionId !== null) {
            $divisionWhere  = 'AND d.id = ?';
            $divisionParams = [$divisionId];
        } else {
            $divisionWhere  = "AND d.level = 1 AND LOWER(d.country_id) = 'de'";
            $divisionParams = [];
        }

        // Previous season ID for club position sorting
        $prevStmt = $this->con->prepare(
            "SELECT id FROM season WHERE start_date < (SELECT start_date FROM season WHERE id = ?)
             ORDER BY start_date DESC LIMIT 1"
        );
        $prevStmt->execute([$seasonId]);
        $prevSeasonId = $prevStmt->fetchColumn() ?: null;

        $stmt = $this->con->prepare(
            "SELECT p.id, p.displayname,
                    pis.position, pis.price, pis.photo_uploaded,
                    pic.club_id,
                    c.name AS club_name, c.short_name AS club_short_name,
                    c.logo_uploaded AS club_logo_uploaded,
                    COALESCE(SUM(pr.points), 0) AS season_points,
                    cis_prev.position AS prev_club_position
             FROM player_in_season pis
             JOIN player p          ON p.id = pis.player_id
             JOIN player_in_club pic ON pic.player_id = p.id AND pic.to_date IS NULL
             JOIN club c            ON c.id = pic.club_id
             JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
             JOIN division d        ON d.id = cis.division_id
             LEFT JOIN player_rating pr ON pr.player_id = p.id
                 AND pr.matchday_id IN (SELECT id FROM matchday WHERE season_id = ?)
             LEFT JOIN club_in_season cis_prev
                 ON cis_prev.club_id = pic.club_id
                 AND cis_prev.season_id = ?
                 AND cis_prev.division_id = cis.division_id
             WHERE pis.season_id = ?
               $divisionWhere
               AND pis.position IS NOT NULL
               AND pis.price IS NOT NULL AND pis.price > 0
               $exclusionClause
             GROUP BY p.id, p.displayname, pis.position, pis.price, pis.photo_uploaded,
                      pic.club_id, c.name, c.short_name, c.logo_uploaded, cis_prev.position
             ORDER BY season_points DESC, pis.price DESC"
        );
        $stmt->execute(array_merge([$seasonId, $prevSeasonId, $seasonId], $divisionParams, $exclusionParams));

        return ['players' => array_map(fn($r) => [
            'id'                 => $r['id'],
            'displayname'        => $r['displayname'],
            'position'           => $r['position'],
            'price'              => (int) $r['price'],
            'season_points'      => (int) $r['season_points'],
            'photo_uploaded'     => (bool) $r['photo_uploaded'],
            'club_id'            => $r['club_id'],
            'club_name'          => $r['club_name'],
            'club_short_name'    => $r['club_short_name'],
            'club_logo_uploaded'  => (bool) $r['club_logo_uploaded'],
            'prev_club_position'  => $r['prev_club_position'] !== null ? (int) $r['prev_club_position'] : null,
            'season_id'           => $seasonId,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function setPlayerPhotoUploaded(string $playerId, string $seasonId): bool
    {
        $q = $this->con->prepare(
            "UPDATE player_in_season SET photo_uploaded = 1 WHERE player_id = :p AND season_id = :s"
        );
        $q->execute([':p' => $playerId, ':s' => $seasonId]);
        return $q->rowCount() > 0;
    }

    public function createPlayerInSeason(string $id, string $playerId, string $seasonId, string $position, int $price): void
    {
        $q = $this->con->prepare(
            "INSERT INTO player_in_season (id, player_id, season_id, position, price)
             VALUES (?, ?, ?, ?, ?)"
        );
        $q->execute([$id, $playerId, $seasonId, $position, $price]);
    }

    /**
     * player_in_season rows (id, position, price) for the given players/season,
     * indexed by player_id. Doubles as an existence check via isset().
     */
    public function getExistingPlayerInSeasonMap(array $playerIds, string $seasonId): array
    {
        if (empty($playerIds)) return [];

        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $q = $this->con->prepare(
            "SELECT id, player_id, position, price
             FROM player_in_season
             WHERE season_id = ? AND player_id IN ($placeholders)"
        );
        $q->execute(array_merge([$seasonId], $playerIds));

        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['player_id']] = $row;
        }
        return $out;
    }

    public function updatePlayerInSeason(string $id, ?string $position, ?int $price): bool
    {
        $sets   = [];
        $params = [':id' => $id];

        if ($position !== null) {
            $sets[] = 'position = :position';
            $params[':position'] = $position;
        }
        if ($price !== null) {
            $sets[] = 'price = :price';
            $params[':price'] = $price;
        }
        if (empty($sets)) return false;

        $q = $this->con->prepare('UPDATE player_in_season SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $q->execute($params);
        return $q->rowCount() > 0;
    }

    private const CSV_VALID_POSITIONS = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];

    /**
     * Parses a bulk player-season-import CSV (semicolon-separated:
     * ID;Vorname;Nachname;Kurzname;Angezeigter Name;Verein;Position;Marktwert;Punkte;Notendurchschnitt)
     * and matches every row against player (kicker_id) and club (name).
     * Writes nothing.
     */
    public function previewCsvImport(string $filePath, string $divisionId): array
    {
        $season = $this->getActiveSeason();
        if (!$season) {
            throw new RuntimeException('Keine aktive Saison konfiguriert');
        }
        $seasonId = $season['id'];

        $handle = fopen($filePath, 'r');
        $parsedRows = [];
        $firstLine  = true;
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($firstLine) { $firstLine = false; continue; }
            if (trim($line) === '') continue;

            $cols = str_getcsv($line, ';');
            if (count($cols) < 8) continue;

            $kickerId = (int) substr($cols[0], 4);
            $position = in_array($cols[6], self::CSV_VALID_POSITIONS, true) ? $cols[6] : null;
            $price    = is_numeric($cols[7]) ? (int) $cols[7] : null;

            $parsedRows[] = [
                'kicker_id'   => $kickerId,
                'first_name'  => $cols[1],
                'last_name'   => $cols[2],
                'displayname' => $cols[4],
                'club_name'   => $cols[5],
                'position'    => $position,
                'price'       => $price,
            ];
        }
        fclose($handle);

        $kickerIds = array_column($parsedRows, 'kicker_id');
        $playerMap = $this->getPlayersByKickerIds($kickerIds);

        $matchedPlayerIds = array_values(array_filter(array_map(
            fn($r) => $playerMap[$r['kicker_id']]['id'] ?? null,
            $parsedRows
        )));
        $existingMap    = $this->getExistingPlayerInSeasonMap($matchedPlayerIds, $seasonId);
        $currentClubMap = $this->getCurrentClubByPlayerIds($matchedPlayerIds);

        // Second-pass check for rows with no kicker_id match: same displayname already in DB
        // under a different kicker_id likely means the CSV's kicker_id is wrong/changed rather
        // than the player being genuinely new.
        $unmatchedDisplaynames = array_values(array_unique(array_map(
            fn($r) => $r['displayname'],
            array_filter($parsedRows, fn($r) => !isset($playerMap[$r['kicker_id']]))
        )));
        $duplicateCandidateMap = $this->getPlayersByDisplaynames($unmatchedDisplaynames);

        $rows = array_map(function ($r) use ($playerMap, $existingMap, $currentClubMap, $duplicateCandidateMap) {
            $player  = $playerMap[$r['kicker_id']] ?? null;
            $club    = $this->findClubByName($r['club_name']);
            $existing = $player ? ($existingMap[$player['id']] ?? null) : null;
            $currentClub = $player ? ($currentClubMap[$player['id']] ?? null) : null;
            $duplicateCandidate = $player ? null : ($duplicateCandidateMap[$r['displayname']] ?? null);

            $alreadyInSeason = $existing !== null;
            $positionPriceMismatch = $existing !== null
                && ($existing['position'] !== $r['position'] || (int) $existing['price'] !== $r['price']);
            // Explicit conflict: both clubs known and different — the only case worth blocking
            // bulk-creation for, since the player might actually be at a different (possibly
            // out-of-league) club per our stale data and would become wrongly purchasable.
            // Missing player_in_club data (no current club on file) is NOT treated as a conflict —
            // that's the normal state for most players right before a new season's transfers have
            // been entered, and blocking on it would defeat the point of this import.
            $clubMismatch = $club && $currentClub && $currentClub['club_id'] !== $club['id'];
            // Positive confirmation (both clubs known and identical) — informational only, shown in
            // the UI tooltip; not required for importable.
            $clubConfirmed = $club && $currentClub && $currentClub['club_id'] === $club['id'];
            // CSV club name couldn't be resolved to a known club at all (no exact/unique fuzzy match).
            // We can't verify it against the player's actual current club (which might be at a
            // different, possibly out-of-league club per our data) — block bulk-creation rather than
            // risk making the player wrongly purchasable under this league's price.
            $clubUnresolved = !$club;

            return [
                'kicker_id'                  => $r['kicker_id'],
                'csv_first_name'             => $r['first_name'],
                'csv_last_name'              => $r['last_name'],
                'csv_displayname'            => $r['displayname'],
                'csv_club_name'              => $r['club_name'],
                'csv_position'               => $r['position'],
                'csv_price'                  => $r['price'],
                'matched_player_id'          => $player['id'] ?? null,
                'matched_displayname'        => $player['displayname'] ?? null,
                'matched_club_id'            => $club['id'] ?? null,
                'club_logo_uploaded'         => $club ? (bool) $club['logo_uploaded'] : false,
                'already_in_season'          => $alreadyInSeason,
                'importable'                 => (bool) ($player && !$alreadyInSeason && $r['position'] && $r['price'] > 0 && !$clubMismatch && !$clubUnresolved),
                'existing_player_in_season_id' => $existing['id'] ?? null,
                'existing_position'          => $existing['position'] ?? null,
                'existing_price'             => $existing !== null ? (int) $existing['price'] : null,
                'position_price_mismatch'    => $positionPriceMismatch,
                'current_player_in_club_id'  => $currentClub['player_in_club_id'] ?? null,
                'current_club_id'            => $currentClub['club_id'] ?? null,
                'current_club_name'          => $currentClub['club_name'] ?? null,
                'current_club_logo_uploaded' => $currentClub ? (bool) $currentClub['logo_uploaded'] : false,
                'club_mismatch'              => $clubMismatch,
                'club_confirmed'             => $clubConfirmed,
                'club_unresolved'            => $clubUnresolved,
                'duplicate_candidate_player_id' => $duplicateCandidate['id'] ?? null,
                'duplicate_candidate_kicker_id' => $duplicateCandidate ? (int) $duplicateCandidate['kicker_id'] : null,
            ];
        }, $parsedRows);

        $missingPlayers = $this->getMissingClubMembers($seasonId, $divisionId, $matchedPlayerIds);

        return [
            'season_id'         => $seasonId,
            'season_start_date' => $season['start_date'],
            'division_id'       => $divisionId,
            'rows'              => $rows,
            'missing_players'   => $missingPlayers,
        ];
    }

    /**
     * Bulk-creates player_in_season rows for the active season from client-confirmed
     * preview rows. Re-resolves the season server-side and re-checks duplicates for
     * race-safety — duplicates/invalid rows are reported in skipped[], not thrown.
     */
    public function importCsvRows(array $rows): array
    {
        $seasonId = $this->getActiveSeasonId();
        if (!$seasonId) {
            throw new RuntimeException('Keine aktive Saison konfiguriert');
        }

        $playerIds   = array_column($rows, 'player_id');
        $existingSet = $this->getExistingPlayerInSeasonMap($playerIds, $seasonId);

        $created = [];
        $skipped = [];
        foreach ($rows as $row) {
            $playerId = $row['player_id'] ?? null;
            $position = $row['position']  ?? null;
            $price    = isset($row['price']) ? (int) $row['price'] : null;

            if (!$playerId || !in_array($position, self::CSV_VALID_POSITIONS, true) || !$price || $price <= 0) {
                $skipped[] = ['player_id' => $playerId, 'reason' => 'invalid_row'];
                continue;
            }
            if (isset($existingSet[$playerId])) {
                $skipped[] = ['player_id' => $playerId, 'reason' => 'already_in_season'];
                continue;
            }

            $id = $this->con->query("SELECT UUID() AS id")->fetchColumn();
            try {
                $this->createPlayerInSeason($id, $playerId, $seasonId, $position, $price);
                $created[] = ['player_id' => $playerId, 'id' => $id];
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $skipped[] = ['player_id' => $playerId, 'reason' => 'already_in_season'];
                    continue;
                }
                throw $e;
            }
        }

        return [
            'season_id'     => $seasonId,
            'created'       => $created,
            'created_count' => count($created),
            'skipped'       => $skipped,
        ];
    }

    /**
     * Count of league-division players for a season.
     * Falls back to level 1 / DE if no division is configured.
     */
    public function getLeaguePlayerCount(?string $seasonId): int
    {
        if (!$seasonId) {
            $seasonId = $this->getActiveSeasonId();
            if (!$seasonId) return 0;
        }

        $divisionId = $this->getLeagueDivisionId();
        if ($divisionId !== null) {
            $divisionWhere = 'AND d.id = :division_id';
            $params        = [':season_id' => $seasonId, ':division_id' => $divisionId];
        } else {
            $divisionWhere = "AND d.level = 1 AND LOWER(d.country_id) = 'de'";
            $params        = [':season_id' => $seasonId];
        }

        $query = $this->con->prepare(
            "SELECT COUNT(DISTINCT pis.player_id) AS cnt
             FROM player_in_season pis
             JOIN player_in_club pic ON pic.player_id = pis.player_id AND pic.to_date IS NULL
             JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
             JOIN division d ON d.id = cis.division_id
             WHERE pis.season_id = :season_id
               $divisionWhere"
        );
        $query->execute($params);
        return (int) $query->fetchColumn();
    }
}
