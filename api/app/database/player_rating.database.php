<?php

trait PlayerRatingTrait
{
    /**
     * Alle player_ratings für einen Spieltag, gefiltert auf einen Club.
     * Gibt player-Basisinfos + position aus player_in_season mit zurück.
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
                   pr.id,
                   pr.grade,
                   pr.participation,
                   pr.goals,
                   pr.assists,
                   pr.clean_sheet,
                   pr.sds,
                   pr.red_card,
                   pr.yellow_red_card,
                   pr.points
            FROM player_in_club pic
            JOIN player p               ON p.id = pic.player_id
            LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = :season_id
            LEFT JOIN player_rating pr  ON pr.player_id = p.id AND pr.matchday_id = :matchday_id
            WHERE pic.club_id = :club_id
              AND pic.to_date IS NULL
            ORDER BY pis.position ASC, p.last_name ASC, p.first_name ASC
        ");
        $query->execute([
            ':matchday_id' => $matchdayId,
            ':club_id'     => $clubId,
            ':season_id'   => $seasonId,
        ]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Erstellt leere player_ratings für alle aktuellen Spieler eines Clubs an einem Spieltag.
     * Verwendet INSERT IGNORE, sodass bestehende Ratings nicht überschrieben werden.
     * Gibt zurück: Anzahl neu erstellter Ratings + IDs von bereits bestehenden.
     */
    public function initPlayerRatingsForClub(string $matchdayId, string $clubId): array
    {
        // Alle aktuellen Spieler des Clubs holen
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
            "INSERT IGNORE INTO player_rating (id, player_id, matchday_id)
             VALUES (UUID(), :player_id, :matchday_id)"
        );

        $checkExisting = $this->con->prepare(
            "SELECT id FROM player_rating
             WHERE player_id = :player_id AND matchday_id = :matchday_id
             LIMIT 1"
        );

        $created  = [];
        $existing = [];

        foreach ($playerRows as $row) {
            // Prüfen ob Rating bereits existiert
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
                ]);
                $created[] = $row['player_id'];
            }
        }

        return [
            'status'   => true,
            'created'  => count($created),
            'existing' => $existing,
        ];
    }

    /**
     * Aktualisiert eine player_rating-Zeile (alle editierbaren Felder).
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
