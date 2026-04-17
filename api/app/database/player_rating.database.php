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
        // Fetch all current players of the club
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
             VALUES (UUID(), :player_id, :matchday_id, :club_id)"
        );

        $checkExisting = $this->con->prepare(
            "SELECT id FROM player_rating
             WHERE player_id = :player_id AND matchday_id = :matchday_id
             LIMIT 1"
        );

        $created  = [];
        $existing = [];

        foreach ($playerRows as $row) {
            // Check if rating already exists
            $checkExisting->execute([
                ':player_id'   => $row['player_id'],
                ':matchday_id' => $matchdayId,
            ]);
            $existingRating = $checkExisting->fetch(PDO::FETCH_ASSOC);

            if ($existingRating) {
                $existing[] = ['player_id' => $row['player_id'], 'displayname' => $row['displayname']];
            } else {
                $insert->execute([
                    ':player_id'   => $row['player_id'],
                    ':matchday_id' => $matchdayId,
                    ':club_id'     => $clubId,
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
        $allowed = ['grade', 'participation', 'goals', 'assists', 'clean_sheet', 'sds', 'red_card', 'yellow_red_card', 'points'];
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
        return $query->rowCount() > 0;
    }
}
