<?php

trait SeasonTrait
{
    public function getSeasonList(): array
    {
        $query = $this->con->prepare("SELECT * FROM season ORDER BY start_date DESC");
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSeasonById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM season WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function getActiveSeason(): array|false
    {
        $query = $this->con->prepare("SELECT * FROM season WHERE start_date <= CURDATE() ORDER BY start_date DESC LIMIT 1");
        $query->execute();
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function createSeason(string $id, string $startDate): void
    {
        $q = $this->con->prepare("INSERT INTO season (id, start_date) VALUES (:id, :start_date)");
        $q->execute([':id' => $id, ':start_date' => $startDate]);
    }
}
