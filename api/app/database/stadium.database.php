<?php

trait StadiumTrait
{
    public function createStadium(
        string $id,
        string $officialName,
        ?string $name,
        ?int $capacity,
        ?float $lat,
        ?float $lng
    ): void {
        $query = $this->con->prepare(
            "INSERT INTO stadium (id, official_name, name, capacity, lat, lng)
             VALUES (:id, :official_name, :name, :capacity, :lat, :lng)"
        );
        $query->execute([
            ':id'            => $id,
            ':official_name' => $officialName,
            ':name'          => $name,
            ':capacity'      => $capacity,
            ':lat'           => $lat,
            ':lng'           => $lng,
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

    public function getAllStadiums(string $excludeManagerId): array
    {
        $query = $this->con->prepare("
            SELECT
                s.id, s.official_name, s.name, s.capacity, s.lat, s.lng,
                c.id             AS club_id,
                c.name           AS club_name,
                c.logo_uploaded  AS club_logo_uploaded
            FROM stadium s
            LEFT JOIN club_stadium cs ON cs.stadium_id = s.id AND cs.to_date IS NULL
            LEFT JOIN club c ON c.id = cs.club_id
            ORDER BY s.official_name ASC
        ");
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        $visitorsQuery = $this->con->prepare("
            SELECT ms.stadium_id, m.id, m.manager_name
            FROM manager_stadium ms
            JOIN manager m ON m.id = ms.manager_id
            WHERE ms.manager_id != :exclude_manager_id
        ");
        $visitorsQuery->execute([':exclude_manager_id' => $excludeManagerId]);

        $visitorsByStadium = [];
        foreach ($visitorsQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $visitorsByStadium[$row['stadium_id']][] = [
                'id'            => $row['id'],
                'manager_name'  => $row['manager_name'],
            ];
        }

        return array_map(function (array $row) use ($visitorsByStadium): array {
            return [
                'id'             => $row['id'],
                'official_name'  => $row['official_name'],
                'name'           => $row['name'],
                'capacity'       => $row['capacity'] !== null ? (int) $row['capacity'] : null,
                'lat'            => $row['lat']      !== null ? (float) $row['lat']    : null,
                'lng'            => $row['lng']      !== null ? (float) $row['lng']    : null,
                'other_visitors' => $visitorsByStadium[$row['id']] ?? [],
                'club'           => $row['club_id'] ? [
                    'id'            => $row['club_id'],
                    'name'          => $row['club_name'],
                    'logo_uploaded' => (bool) $row['club_logo_uploaded'],
                ] : null,
            ];
        }, $rows);
    }
}
