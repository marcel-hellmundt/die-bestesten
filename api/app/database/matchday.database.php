<?php

trait MatchdayTrait
{
    public function getMatchdayList(?string $seasonId = null): array
    {
        if ($seasonId) {
            $query = $this->con->prepare("SELECT * FROM matchday WHERE season_id = :season_id ORDER BY number DESC");
            $query->execute([':season_id' => $seasonId]);
        } else {
            $query = $this->con->prepare("SELECT * FROM matchday ORDER BY start_date DESC");
            $query->execute();
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMatchdayById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM matchday WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function migrateMatchday(): array
    {
        $rows = $this->con_old->query(
            "SELECT matchday_id, season_id, number, start_date, kickoff_date FROM matchday"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->con->prepare(
            "INSERT INTO matchday (id, season_id, number, start_date, kickoff_date)
             VALUES (:id, :season_id, :number, :start_date, :kickoff_date)
             ON DUPLICATE KEY UPDATE
               season_id    = VALUES(season_id),
               number       = VALUES(number),
               start_date   = VALUES(start_date),
               kickoff_date = VALUES(kickoff_date)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':id'           => $row['matchday_id'],
                ':season_id'    => $row['season_id'],
                ':number'       => $row['number'],
                ':start_date'   => $row['start_date'],
                ':kickoff_date' => $row['kickoff_date'],
            ]);
        }

        return ['status' => true, 'migrated' => count($rows)];
    }
}
