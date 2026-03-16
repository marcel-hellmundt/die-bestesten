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
}
