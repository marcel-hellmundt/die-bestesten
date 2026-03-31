<?php

trait ClubTrait
{
    public function getClubList(?string $countryId = null): array
    {
        if ($countryId) {
            $query = $this->con->prepare("SELECT * FROM club WHERE country_id = :country_id ORDER BY name ASC");
            $query->execute([':country_id' => $countryId]);
        } else {
            $query = $this->con->prepare("SELECT * FROM club ORDER BY name ASC");
            $query->execute();
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getClubById(string $id): array|false
    {
        $query = $this->con->prepare("
            SELECT
                c.*,
                s.id          AS stadium_id,
                s.official_name AS stadium_official_name,
                s.name        AS stadium_name,
                s.capacity    AS stadium_capacity,
                s.lat         AS stadium_lat,
                s.lng         AS stadium_lng,
                s.opened_date AS stadium_opened_date
            FROM club c
            LEFT JOIN club_stadium cs ON cs.club_id = c.id AND cs.to_date IS NULL
            LEFT JOIN stadium s ON s.id = cs.stadium_id
            WHERE c.id = :id
            LIMIT 1
        ");
        $query->execute([':id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $stadium = null;
        if ($row['stadium_id']) {
            $stadium = [
                'id'            => $row['stadium_id'],
                'official_name' => $row['stadium_official_name'],
                'name'          => $row['stadium_name'],
                'capacity'      => $row['stadium_capacity'] !== null ? (int) $row['stadium_capacity'] : null,
                'lat'           => $row['stadium_lat'] !== null ? (float) $row['stadium_lat'] : null,
                'lng'           => $row['stadium_lng'] !== null ? (float) $row['stadium_lng'] : null,
                'opened_date'   => $row['stadium_opened_date'],
            ];
        }

        return [
            'id'            => $row['id'],
            'country_id'    => $row['country_id'],
            'name'          => $row['name'],
            'short_name'    => $row['short_name'],
            'logo_uploaded' => (bool) $row['logo_uploaded'],
            'stadium'       => $stadium,
        ];
    }

    public function migrateClub(): array
    {
        $rows = $this->con_old->query("SELECT club_id, country_code, name FROM club")
            ->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->con->prepare(
            "INSERT INTO club (id, country_id, name) VALUES (:id, :country_id, :name)
             ON DUPLICATE KEY UPDATE country_id = VALUES(country_id), name = VALUES(name)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':id'         => $row['club_id'],
                ':country_id' => $row['country_code'],
                ':name'       => $row['name'],
            ]);
        }

        return ['status' => true, 'migrated' => count($rows)];
    }
}
