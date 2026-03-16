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
        $query = $this->con->prepare("SELECT * FROM club WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
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
