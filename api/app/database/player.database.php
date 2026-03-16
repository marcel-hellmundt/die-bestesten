<?php

trait PlayerTrait
{
    public function getPlayerList(?string $countryId = null): array
    {
        if ($countryId) {
            $query = $this->con->prepare("SELECT * FROM player WHERE country_id = :country_id ORDER BY last_name ASC, first_name ASC");
            $query->execute([':country_id' => $countryId]);
        } else {
            $query = $this->con->prepare("SELECT * FROM player ORDER BY last_name ASC, first_name ASC");
            $query->execute();
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPlayerDetail(string $id, ?string $seasonId = null): array|false
    {
        $player = $this->getPlayerById($id);
        if (!$player) return false;

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

        // Resolve season: use provided season_id or fall back to active season
        if (!$seasonId) {
            $q = $this->con->prepare("SELECT id FROM season ORDER BY start_date DESC LIMIT 1");
            $q->execute();
            $season = $q->fetch(PDO::FETCH_ASSOC);
            $seasonId = $season['id'] ?? null;
        }

        // Season data for this player
        if ($seasonId) {
            $q = $this->con->prepare("
                SELECT price, position, photo_uploaded
                FROM player_in_season
                WHERE player_id = :player_id AND season_id = :season_id
                LIMIT 1
            ");
            $q->execute([':player_id' => $id, ':season_id' => $seasonId]);
            $player['current_season'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $player['current_season'] = null;
        }

        return $player;
    }

    private function getPlayerById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM player WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }
}
