<?php

trait ClubTrait
{
    private const CLUB_STADIUM_SELECT = "
        c.id, c.country_id, c.name, c.short_name, c.logo_uploaded,
        s.id             AS stadium_id,
        s.official_name  AS stadium_official_name,
        s.name           AS stadium_name,
        s.capacity       AS stadium_capacity,
        s.lat            AS stadium_lat,
        s.lng            AS stadium_lng,
        s.opened_date    AS stadium_opened_date
        FROM club c
        LEFT JOIN club_stadium cs ON cs.club_id = c.id AND cs.to_date IS NULL
        LEFT JOIN stadium s ON s.id = cs.stadium_id
    ";

    public function getClubList(?string $countryId = null): array
    {
        $sql = "SELECT " . self::CLUB_STADIUM_SELECT;
        if ($countryId) {
            $sql .= " WHERE c.country_id = :country_id";
        }
        $sql .= " ORDER BY c.name ASC";

        $query = $this->con->prepare($sql);
        $query->execute($countryId ? [':country_id' => $countryId] : []);
        return array_map([$this, 'shapeClubRow'], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getClubById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT " . self::CLUB_STADIUM_SELECT . " WHERE c.id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->shapeClubRow($row) : false;
    }

    private function shapeClubRow(array $row): array
    {
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

    public function setClubLogoUploaded(string $id): void
    {
        $this->con->prepare("UPDATE club SET logo_uploaded = 1 WHERE id = :id")->execute([':id' => $id]);
    }
}
