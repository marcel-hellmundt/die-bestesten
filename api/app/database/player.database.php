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

        return $player;
    }

    private function getPlayerById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM player WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private function getActiveSeasonId(): ?string
    {
        $q = $this->con->prepare("SELECT id FROM season ORDER BY start_date DESC LIMIT 1");
        $q->execute();
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row['id'] ?? null;
    }
}
