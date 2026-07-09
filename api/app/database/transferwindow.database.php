<?php

trait TransferwindowTrait
{
    public function getTransferwindowList(?string $matchdayId = null, ?string $seasonId = null, ?string $divisionId = null): array
    {
        if ($matchdayId) {
            $query = $this->con->prepare(
                "SELECT * FROM transferwindow WHERE matchday_id = :matchday_id ORDER BY start_date ASC"
            );
            $query->execute([':matchday_id' => $matchdayId]);
        } elseif ($seasonId) {
            $divisionId = $divisionId ?? $this->getLeagueDivisionId();
            if ($divisionId !== null) {
                $query = $this->con->prepare(
                    "SELECT tw.* FROM transferwindow tw
                     JOIN matchday m ON m.id = tw.matchday_id
                     WHERE m.season_id = :season_id AND m.division_id = :division_id
                     ORDER BY tw.start_date ASC"
                );
                $query->execute([':season_id' => $seasonId, ':division_id' => $divisionId]);
            } else {
                $query = $this->con->prepare(
                    "SELECT tw.* FROM transferwindow tw
                     JOIN matchday m ON m.id = tw.matchday_id
                     JOIN division d ON d.id = m.division_id
                     WHERE m.season_id = :season_id AND d.level = 1 AND LOWER(d.country_id) = 'de'
                     ORDER BY tw.start_date ASC"
                );
                $query->execute([':season_id' => $seasonId]);
            }
        } else {
            $query = $this->con->prepare("SELECT * FROM transferwindow ORDER BY start_date ASC");
            $query->execute();
        }
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $countQuery = $this->con_league->prepare(
                "SELECT transferwindow_id, COUNT(*) AS cnt FROM offer WHERE transferwindow_id IN ($ph) GROUP BY transferwindow_id"
            );
            $countQuery->execute($ids);
            $counts = array_column($countQuery->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'transferwindow_id');
            foreach ($rows as &$row) {
                $row['offer_count'] = (int) ($counts[$row['id']] ?? 0);
            }
            unset($row);
        }

        return $rows;
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

    public function updateTransferwindow(string $id, string $startDate, string $endDate): array
    {
        $this->con->prepare(
            "UPDATE transferwindow SET start_date = :start_date, end_date = :end_date WHERE id = :id"
        )->execute([':start_date' => $startDate, ':end_date' => $endDate, ':id' => $id]);
        return ['status' => true];
    }

    public function deleteTransferwindow(string $id): array
    {
        $tw = $this->getTransferwindowById($id);
        if (!$tw) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Transferwindow not found'];
        }

        $offerCount = $this->con_league->prepare("SELECT COUNT(*) FROM offer WHERE transferwindow_id = ?");
        $offerCount->execute([$id]);
        if ((int) $offerCount->fetchColumn() > 0) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Transferfenster hat bereits Gebote und kann nicht gelöscht werden'];
        }

        $this->con->prepare("DELETE FROM transferwindow WHERE id = :id")->execute([':id' => $id]);
        return ['status' => true];
    }
}
