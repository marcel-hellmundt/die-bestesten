<?php

trait ClubInSeasonTrait
{
    public function getClubInSeasonByClub(string $clubId): array
    {
        $query = $this->con->prepare(
            "SELECT cis.*, s.start_date AS season_start
             FROM club_in_season cis
             JOIN season s ON s.id = cis.season_id
             WHERE cis.club_id = :club_id
             ORDER BY s.start_date DESC"
        );
        $query->execute([':club_id' => $clubId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getClubInSeasonBySeason(string $seasonId): array
    {
        $query = $this->con->prepare(
            "SELECT * FROM club_in_season
             WHERE season_id = :season_id
             ORDER BY position ASC"
        );
        $query->execute([':season_id' => $seasonId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
