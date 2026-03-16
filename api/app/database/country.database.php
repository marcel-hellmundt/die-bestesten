<?php

trait CountryTrait
{
    public function getCountryList(): array
    {
        $query = $this->con->prepare("SELECT * FROM country ORDER BY name ASC");
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCountryById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM country WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function migrateCountry(): array
    {
        $rows = $this->con_old->query("SELECT country_code, country_name FROM country")
            ->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->con->prepare(
            "INSERT INTO country (id, name) VALUES (:id, :name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':id'   => $row['country_code'],
                ':name' => $row['country_name'],
            ]);
        }

        return ['status' => true, 'migrated' => count($rows)];
    }
}
