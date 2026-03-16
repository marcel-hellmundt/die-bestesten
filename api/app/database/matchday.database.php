<?php

trait MatchdayTrait
{
    public function getMatchdayList(?string $seasonId = null): array
    {
        if ($seasonId) {
            $query = $this->con->prepare("SELECT * FROM matchday WHERE season_id = :season_id ORDER BY number ASC");
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
}
