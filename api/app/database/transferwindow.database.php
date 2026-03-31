<?php

trait TransferwindowTrait
{
    public function getTransferwindowList(?string $matchdayId = null, ?string $seasonId = null): array
    {
        if ($matchdayId) {
            $query = $this->con->prepare(
                "SELECT * FROM transferwindow WHERE matchday_id = :matchday_id ORDER BY start_date ASC"
            );
            $query->execute([':matchday_id' => $matchdayId]);
        } elseif ($seasonId) {
            $query = $this->con->prepare(
                "SELECT tw.* FROM transferwindow tw
                 JOIN matchday m ON m.id = tw.matchday_id
                 WHERE m.season_id = :season_id
                 ORDER BY tw.start_date ASC"
            );
            $query->execute([':season_id' => $seasonId]);
        } else {
            $query = $this->con->prepare("SELECT * FROM transferwindow ORDER BY start_date ASC");
            $query->execute();
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTransferwindowById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM transferwindow WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function createTransferwindow(string $matchdayId, string $startDate, string $endDate): array
    {
        $id = $this->con->query("SELECT UUID() AS id")->fetchColumn();
        $stmt = $this->con->prepare(
            "INSERT INTO transferwindow (id, matchday_id, start_date, end_date)
             VALUES (:id, :matchday_id, :start_date, :end_date)"
        );
        $stmt->execute([
            ':id'          => $id,
            ':matchday_id' => $matchdayId,
            ':start_date'  => $startDate,
            ':end_date'    => $endDate,
        ]);
        return $this->getTransferwindowById($id);
    }

    public function migrateTransferwindow(): array
    {
        $rows = $this->con_old->query(
            "SELECT transferwindow_id, matchday_id, start_date, end_date FROM transferwindow"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->con->prepare(
            "INSERT INTO transferwindow (id, matchday_id, start_date, end_date)
             VALUES (:id, :matchday_id, :start_date, :end_date)
             ON DUPLICATE KEY UPDATE
               matchday_id = VALUES(matchday_id),
               start_date  = VALUES(start_date),
               end_date    = VALUES(end_date)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':id'          => $row['transferwindow_id'],
                ':matchday_id' => $row['matchday_id'],
                ':start_date'  => $row['start_date'],
                ':end_date'    => $row['end_date'],
            ]);
        }

        return ['status' => true, 'migrated' => count($rows)];
    }
}
