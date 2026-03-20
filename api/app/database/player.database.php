<?php

trait PlayerTrait
{
    public function getPlayerList(?string $countryId = null, ?string $seasonId = null): array
    {
        $seasonId = $seasonId ?? $this->getActiveSeasonId();

        $sql = "
            SELECT p.*,
                   COALESCE(SUM(pr.points), 0) AS total_points
            FROM player p
            LEFT JOIN player_rating pr ON p.id = pr.player_id
            LEFT JOIN matchday m       ON pr.matchday_id = m.id AND m.season_id = :season_id
        ";

        $params = [':season_id' => $seasonId];

        if ($countryId) {
            $sql .= " WHERE p.country_id = :country_id";
            $params[':country_id'] = $countryId;
        }

        $sql .= " GROUP BY p.id ORDER BY p.last_name ASC, p.first_name ASC";

        $query = $this->con->prepare($sql);
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPlayerDetail(string $id, ?string $seasonId = null): array|false
    {
        $player = $this->getPlayerById($id);
        if (!$player) return false;

        $seasonId = $seasonId ?? $this->getActiveSeasonId();

        // Current club (to_date IS NULL = current contract)
        $q = $this->con->prepare("
            SELECT pc.from_date, pc.on_loan, c.id AS club_id, c.name, c.short_name
            FROM player_in_club pc
            JOIN club c ON pc.club_id = c.id
            WHERE pc.player_id = :id AND pc.to_date IS NULL
            LIMIT 1
        ");
        $q->execute([':id' => $id]);
        $player['current_club'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;

        // Season data and ratings
        if ($seasonId) {
            $q = $this->con->prepare("
                SELECT price, position, photo_uploaded
                FROM player_in_season
                WHERE player_id = :player_id AND season_id = :season_id
                LIMIT 1
            ");
            $q->execute([':player_id' => $id, ':season_id' => $seasonId]);
            $player['current_season'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;

            $q = $this->con->prepare("
                SELECT pr.id, pr.grade, pr.is_starting, pr.is_substitute,
                       pr.goals, pr.assists, pr.clean_sheet,
                       pr.red_card, pr.yellow_red_card, pr.points,
                       m.number AS matchday_number, m.kickoff_date
                FROM player_rating pr
                JOIN matchday m ON pr.matchday_id = m.id
                WHERE pr.player_id = :player_id AND m.season_id = :season_id
                ORDER BY m.number ASC
            ");
            $q->execute([':player_id' => $id, ':season_id' => $seasonId]);
            $player['ratings'] = $q->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $player['current_season'] = null;
            $player['ratings']        = [];
        }

        // All seasons (sorted newest first)
        $q = $this->con->prepare("
            SELECT pis.season_id, pis.price, pis.position, pis.photo_uploaded,
                   s.start_date AS season_start
            FROM player_in_season pis
            JOIN season s ON s.id = pis.season_id
            WHERE pis.player_id = :player_id
            ORDER BY s.start_date DESC
        ");
        $q->execute([':player_id' => $id]);
        $player['seasons'] = $q->fetchAll(PDO::FETCH_ASSOC);

        // All club stints (sorted newest first)
        $q = $this->con->prepare("
            SELECT pic.club_id, pic.from_date, pic.to_date, pic.on_loan,
                   c.name AS club_name, c.logo_uploaded
            FROM player_in_club pic
            JOIN club c ON pic.club_id = c.id
            WHERE pic.player_id = :player_id
            ORDER BY pic.from_date DESC
        ");
        $q->execute([':player_id' => $id]);
        $player['clubs'] = $q->fetchAll(PDO::FETCH_ASSOC);

        return $player;
    }

    private function getPlayerById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM player WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function migratePlayer(): array
    {
        $rows = $this->con_old->query("
            SELECT player_id, country_code, firstname, lastname, displayname,
                   city, date_of_birth, height, weight
            FROM player
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->con->prepare(
            "INSERT INTO player
                (id, country_id, first_name, last_name, displayname, birth_city, date_of_birth, height_cm, weight_kg)
             VALUES
                (:id, :country_id, :first_name, :last_name, :displayname, :birth_city, :date_of_birth, :height_cm, :weight_kg)
             ON DUPLICATE KEY UPDATE
               country_id     = VALUES(country_id),
               first_name     = VALUES(first_name),
               last_name      = VALUES(last_name),
               displayname    = VALUES(displayname),
               birth_city     = VALUES(birth_city),
               date_of_birth  = VALUES(date_of_birth),
               height_cm      = VALUES(height_cm),
               weight_kg      = VALUES(weight_kg)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':id'           => $row['player_id'],
                ':country_id'   => $row['country_code'],
                ':first_name'   => $row['firstname'],
                ':last_name'    => $row['lastname'],
                ':displayname'  => $row['displayname'],
                ':birth_city'   => $row['city'],
                ':date_of_birth'=> $row['date_of_birth'],
                ':height_cm'    => $row['height'],
                ':weight_kg'    => $row['weight'],
            ]);
        }

        return ['status' => true, 'migrated' => count($rows)];
    }

    private function getActiveSeasonId(): ?string
    {
        $q = $this->con->prepare("SELECT id FROM season ORDER BY start_date DESC LIMIT 1");
        $q->execute();
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row['id'] ?? null;
    }
}
