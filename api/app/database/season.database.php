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
        $query = $this->con->prepare("SELECT * FROM season ORDER BY start_date DESC LIMIT 1");
        $query->execute();
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function migrateSeason(): array
    {
        $rows = $this->con_old->query("SELECT season_id, start_date FROM season")
            ->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->con->prepare(
            "INSERT INTO season (id, start_date) VALUES (:id, :start_date)
             ON DUPLICATE KEY UPDATE start_date = VALUES(start_date)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':id'         => $row['season_id'],
                ':start_date' => $row['start_date'],
            ]);
        }

        return ['status' => true, 'migrated' => count($rows)];
    }
}
