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

    public function updateClubInSeason(string $id, ?string $divisionId, mixed $position): void
    {
        $sets   = [];
        $params = [':id' => $id];

        if ($divisionId !== null) {
            $sets[]               = 'division_id = :division_id';
            $params[':division_id'] = $divisionId;
        }
        if ($position !== null) {
            $sets[]            = 'position = :position';
            $params[':position'] = (int) $position;
        } else {
            $sets[]  = 'position = NULL';
        }

        $query = $this->con->prepare(
            'UPDATE club_in_season SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $query->execute($params);
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

    public function clubInSeasonExists(string $clubId, string $seasonId): bool
    {
        $query = $this->con->prepare(
            "SELECT COUNT(*) FROM club_in_season WHERE club_id = :club_id AND season_id = :season_id"
        );
        $query->execute([':club_id' => $clubId, ':season_id' => $seasonId]);
        return (int) $query->fetchColumn() > 0;
    }

    public function createClubInSeason(string $id, string $clubId, string $seasonId, ?string $divisionId, ?int $position): void
    {
        $query = $this->con->prepare(
            "INSERT INTO club_in_season (id, club_id, season_id, division_id, position)
             VALUES (:id, :club_id, :season_id, :division_id, :position)"
        );
        $query->execute([
            ':id'          => $id,
            ':club_id'     => $clubId,
            ':season_id'   => $seasonId,
            ':division_id' => $divisionId,
            ':position'    => $position,
        ]);
    }
}
