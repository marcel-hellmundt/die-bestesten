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
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Creates empty player_ratings for all current players of a club on a matchday.
     * Uses INSERT IGNORE so existing ratings are not overwritten.
     * Returns: count of newly created ratings + IDs of existing ones.
     */
    public function initPlayerRatingsForClub(string $matchdayId, string $clubId): array
    {
        $matchday     = $this->getMatchdayById($matchdayId);
        $seasonId     = $matchday['season_id'];
        $matchdayNum  = (int) $matchday['number'];

        $players = $this->con->prepare(
            "SELECT p.id AS player_id, p.displayname
             FROM player_in_club pic
             JOIN player p ON p.id = pic.player_id
             WHERE pic.club_id = :club_id AND pic.to_date IS NULL
             ORDER BY p.last_name ASC"
        );
        $players->execute([':club_id' => $clubId]);
        $playerRows = $players->fetchAll(PDO::FETCH_ASSOC);

        $insert = $this->con->prepare(
            "INSERT IGNORE INTO player_rating (id, player_id, matchday_id, club_id)
             VALUES (:id, :player_id, :matchday_id, :club_id)"
        );

        $insertOld = $this->con_old->prepare(
            "INSERT IGNORE INTO player_rating
                (player_rating_id, player_id, club_id, season_id, matchday,
                 grade, start_lineup, substitution,
                 goals, assists, clean_sheet, sds, red_card, yellow_red_card, points)
             VALUES
                (:id, :player_id, :club_id, :season_id, :matchday,
                 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL)"
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
                $insertOld->execute([
                    ':id'        => $newId,
                    ':player_id' => $row['player_id'],
                    ':club_id'   => $clubId,
                    ':season_id' => $seasonId,
                    ':matchday'  => $matchdayNum,
                ]);
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
     * [{club_id, rating_count, starter_count, grade_count, goals, assists, has_sds}]
     */
    public function getClubStatusByMatchday(string $matchdayId): array
    {
        $query = $this->con->prepare("
            SELECT club_id,
                   COUNT(*)                                    AS rating_count,
                   SUM(participation = 'starting')             AS starter_count,
                   SUM(grade IS NOT NULL)                      AS grade_count,
                   SUM(goals)                                  AS goals,
                   SUM(assists)                                AS assists,
                   MAX(sds)                                    AS has_sds
            FROM player_rating
            WHERE matchday_id = :matchday_id
            GROUP BY club_id
        ");
        $query->execute([':matchday_id' => $matchdayId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns all player_ratings for a matchday with player displayname.
     */
    public function getPlayerRatingsForMatchday(string $matchdayId): array
    {
        $query = $this->con->prepare("
            SELECT p.displayname, pr.points
            FROM player_rating pr
            JOIN player p ON p.id = pr.player_id
            WHERE pr.matchday_id = :matchday_id
        ");
        $query->execute([':matchday_id' => $matchdayId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
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
        return $query->fetch(PDO::FETCH_ASSOC);
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
    public function updatePlayerRating(string $id, array $data): bool
    {
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

        // Mirror to old DB
        $oldSets   = [];
        $oldParams = [':id' => $id];
        foreach ($data as $field => $value) {
            if ($field === 'participation') {
                $oldSets[]                  = 'start_lineup = :start_lineup';
                $oldSets[]                  = 'substitution = :substitution';
                $oldParams[':start_lineup'] = ($value === 'starting')  ? 1 : 0;
                $oldParams[':substitution'] = ($value === 'substitute') ? 1 : 0;
            } elseif (in_array($field, ['grade', 'goals', 'assists', 'clean_sheet', 'sds', 'red_card', 'yellow_red_card', 'points'])) {
                $oldSets[]            = "$field = :$field";
                // Old DB uses 0 for "not set"; new DB uses null
                $oldParams[":$field"] = $value ?? 0;
            }
        }
        if (!empty($oldSets)) {
            $oldQuery = $this->con_old->prepare(
                'UPDATE player_rating SET ' . implode(', ', $oldSets) . ' WHERE player_rating_id = :id'
            );
            $oldQuery->execute($oldParams);
        }

        // Always recalculate and persist points
        $newPoints = $this->calculatePoints($id);
        $this->con->prepare('UPDATE player_rating SET points = :p WHERE id = :id')
            ->execute([':p' => $newPoints, ':id' => $id]);
        $this->con_old->prepare('UPDATE player_rating SET points = :p WHERE player_rating_id = :id')
            ->execute([':p' => $newPoints, ':id' => $id]);

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
