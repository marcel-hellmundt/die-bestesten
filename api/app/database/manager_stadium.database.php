<?php

trait ManagerStadiumTrait
{
    public function getVisitedStadiumIds(string $managerId): array
    {
        $query = $this->con->prepare("SELECT stadium_id FROM manager_stadium WHERE manager_id = :manager_id");
        $query->execute([':manager_id' => $managerId]);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    public function markStadiumVisited(string $id, string $managerId, string $stadiumId): void
    {
        $query = $this->con->prepare(
            "INSERT IGNORE INTO manager_stadium (id, manager_id, stadium_id) VALUES (:id, :manager_id, :stadium_id)"
        );
        $query->execute([':id' => $id, ':manager_id' => $managerId, ':stadium_id' => $stadiumId]);
    }

    public function unmarkStadiumVisited(string $managerId, string $stadiumId): void
    {
        $query = $this->con->prepare(
            "DELETE FROM manager_stadium WHERE manager_id = :manager_id AND stadium_id = :stadium_id"
        );
        $query->execute([':manager_id' => $managerId, ':stadium_id' => $stadiumId]);
    }
}
