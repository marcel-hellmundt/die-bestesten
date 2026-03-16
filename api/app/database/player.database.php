<?php

trait PlayerTrait
{
    public function getPlayerList(?string $countryId = null): array
    {
        if ($countryId) {
            $query = $this->con->prepare("SELECT * FROM player WHERE country_id = :country_id ORDER BY last_name ASC, first_name ASC");
            $query->execute([':country_id' => $countryId]);
        } else {
            $query = $this->con->prepare("SELECT * FROM player ORDER BY last_name ASC, first_name ASC");
            $query->execute();
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPlayerById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM player WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }
}
