<?php

trait StadiumTrait
{
    public function createStadium(
        string $id,
        string $officialName,
        ?string $name,
        ?int $capacity,
        ?float $lat,
        ?float $lng,
        ?string $openedDate
    ): void {
        $query = $this->con->prepare(
            "INSERT INTO stadium (id, official_name, name, capacity, lat, lng, opened_date)
             VALUES (:id, :official_name, :name, :capacity, :lat, :lng, :opened_date)"
        );
        $query->execute([
            ':id'            => $id,
            ':official_name' => $officialName,
            ':name'          => $name,
            ':capacity'      => $capacity,
            ':lat'           => $lat,
            ':lng'           => $lng,
            ':opened_date'   => $openedDate,
        ]);
    }

    public function linkClubStadium(string $id, string $clubId, string $stadiumId, string $fromDate): void
    {
        $query = $this->con->prepare(
            "INSERT INTO club_stadium (id, club_id, stadium_id, from_date)
             VALUES (:id, :club_id, :stadium_id, :from_date)"
        );
        $query->execute([
            ':id'         => $id,
            ':club_id'    => $clubId,
            ':stadium_id' => $stadiumId,
            ':from_date'  => $fromDate,
        ]);
    }

    public function getAllStadiums(): array
    {
        $query = $this->con->prepare("
            SELECT
                s.id, s.official_name, s.name, s.capacity, s.lat, s.lng, s.opened_date,
                c.id   AS club_id,
                c.name AS club_name
            FROM stadium s
            LEFT JOIN club_stadium cs ON cs.stadium_id = s.id AND cs.to_date IS NULL
            LEFT JOIN club c ON c.id = cs.club_id
            ORDER BY s.official_name ASC
        ");
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            return [
                'id'            => $row['id'],
                'official_name' => $row['official_name'],
                'name'          => $row['name'],
                'capacity'      => $row['capacity'] !== null ? (int) $row['capacity'] : null,
                'lat'           => $row['lat']      !== null ? (float) $row['lat']    : null,
                'lng'           => $row['lng']      !== null ? (float) $row['lng']    : null,
                'opened_date'   => $row['opened_date'],
                'club'          => $row['club_id'] ? ['id' => $row['club_id'], 'name' => $row['club_name']] : null,
            ];
        }, $rows);
    }
}
