<?php

trait PlayerInSeasonTrait
{
    /**
     * Count of players in the 1. Bundesliga (level 1, country_id 'de') for a season.
     * If no season_id is provided, the active season is used.
     */
    public function getBundesligaPlayerCount(?string $seasonId): int
    {
        if (!$seasonId) {
            $stmt = $this->con->query("SELECT id FROM season ORDER BY start_date DESC LIMIT 1");
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 0;
            $seasonId = $row['id'];
        }

        $query = $this->con->prepare(
            "SELECT COUNT(DISTINCT pis.player_id) AS cnt
             FROM player_in_season pis
             JOIN player_in_club pic ON pic.player_id = pis.player_id AND pic.to_date IS NULL
             JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
             JOIN division d ON d.id = cis.division_id
             WHERE pis.season_id = :season_id
               AND d.level = 1
               AND LOWER(d.country_id) = 'de'"
        );
        $query->execute([':season_id' => $seasonId]);
        return (int) $query->fetchColumn();
    }
}
