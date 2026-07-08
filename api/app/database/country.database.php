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
}
