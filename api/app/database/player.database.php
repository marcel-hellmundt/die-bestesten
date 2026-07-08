<?php

trait PlayerTrait
{
    public function getPlayersInClub(string $clubId, ?string $seasonId = null): array
    {
        $seasonId = $seasonId ?? $this->getActiveSeasonId();

        $sql = "
            SELECT p.id, p.first_name, p.last_name, p.displayname, p.country_id,
                   pis.position AS season_position
            FROM player_in_club pic
            JOIN player p ON pic.player_id = p.id
            LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = :season_id
            WHERE pic.club_id = :club_id AND pic.to_date IS NULL
            ORDER BY
                CASE WHEN pis.position IS NULL THEN 1 ELSE 0 END,
                FIELD(pis.position, 'GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'),
                p.last_name ASC, p.first_name ASC
        ";

        $query = $this->con->prepare($sql);
        $query->execute([':club_id' => $clubId, ':season_id' => $seasonId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

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
                SELECT pr.id, pr.grade, pr.participation,
                       pr.goals, pr.assists, pr.clean_sheet,
                       pr.sds, pr.red_card, pr.yellow_red_card, pr.points,
                       pr.club_id, c.logo_uploaded AS club_logo_uploaded,
                       m.number AS matchday_number, m.kickoff_date
                FROM player_rating pr
                JOIN matchday m  ON pr.matchday_id = m.id
                LEFT JOIN club c ON c.id = pr.club_id
                WHERE pr.player_id = :player_id AND m.season_id = :season_id
                ORDER BY m.number ASC
            ");
            $q->execute([':player_id' => $id, ':season_id' => $seasonId]);
            $player['ratings'] = $q->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $player['current_season'] = null;
            $player['ratings']        = [];
        }

        // All seasons (sorted newest first) with aggregated points
        $q = $this->con->prepare("
            SELECT pis.season_id, pis.price, pis.position, pis.photo_uploaded,
                   s.start_date AS season_start,
                   COALESCE(SUM(pr.points), 0) AS total_points
            FROM player_in_season pis
            JOIN season s ON s.id = pis.season_id
            LEFT JOIN matchday m ON m.season_id = pis.season_id
            LEFT JOIN player_rating pr ON pr.player_id = pis.player_id AND pr.matchday_id = m.id
            WHERE pis.player_id = :player_id
            GROUP BY pis.season_id, pis.price, pis.position, pis.photo_uploaded, s.start_date
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

    public function createPlayer(array $body): array
    {
        $playerId = $this->con->query("SELECT UUID() AS id")->fetchColumn();

        $stmt = $this->con->prepare("
            INSERT INTO player (id, kicker_id, first_name, last_name, displayname)
            VALUES (:id, :kicker_id, :first_name, :last_name, :displayname)
        ");
        $stmt->execute([
            ':id'          => $playerId,
            ':kicker_id'   => $body['kicker_id'],
            ':first_name'  => $body['first_name'],
            ':last_name'   => $body['last_name'],
            ':displayname' => $body['displayname'],
        ]);

        $stmt = $this->con->prepare("
            INSERT INTO player_in_season (id, player_id, season_id, price, position, photo_uploaded)
            VALUES (:id, :player_id, :season_id, :price, :position, 0)
        ");
        $stmt->execute([
            ':id'        => $this->con->query("SELECT UUID() AS id")->fetchColumn(),
            ':player_id' => $playerId,
            ':season_id' => $body['season_id'],
            ':price'     => $body['price'],
            ':position'  => $body['position'],
        ]);

        if (!empty($body['club_id'])) {
            $fromDate = $body['from_date'] ?? date('Y-m-d');
            $stmt = $this->con->prepare("
                INSERT INTO player_in_club (id, player_id, club_id, from_date, to_date, on_loan)
                VALUES (:id, :player_id, :club_id, :from_date, NULL, 0)
            ");
            $stmt->execute([
                ':id'        => $this->con->query("SELECT UUID() AS id")->fetchColumn(),
                ':player_id' => $playerId,
                ':club_id'   => $body['club_id'],
                ':from_date' => $fromDate,
            ]);
        }

        return ['id' => $playerId];
    }

}
