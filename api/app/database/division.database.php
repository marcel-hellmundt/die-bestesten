<?php

trait DivisionTrait
{
    public function getDivisionList(): array
    {
        $query = $this->con->prepare("SELECT * FROM division ORDER BY level ASC, name ASC");
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDivisionById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM division WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function updateDivisionConfig(string $id, int $startingBudget, int $pointsBonus): void
    {
        $q = $this->con->prepare(
            "UPDATE division SET starting_budget = :starting_budget, points_bonus = :points_bonus WHERE id = :id"
        );
        $q->execute([
            ':starting_budget' => $startingBudget,
            ':points_bonus'    => $pointsBonus,
            ':id'              => $id,
        ]);
    }
}
