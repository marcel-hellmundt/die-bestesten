<?php

trait ManagerTrait
{
    public function getManagerById(string $id): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT id, manager_name, alias, role, status FROM manager WHERE id = :id LIMIT 1"
        );
        $q->execute([':id' => $id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function updateManagerPassword(string $id, string $hashedPassword): bool
    {
        $q = $this->con_league->prepare(
            "UPDATE manager SET password = :password WHERE id = :id"
        );
        $q->execute([':password' => $hashedPassword, ':id' => $id]);
        return $q->rowCount() > 0;
    }


}
