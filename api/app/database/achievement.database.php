<?php

trait AchievementTrait
{
    public function evaluateAchievements(): void
    {
        $achievements = $this->con->query(
            "SELECT id, condition_key FROM achievement ORDER BY sort_index ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($achievements)) return;

        $managerIds = $this->con_league->query(
            "SELECT id FROM manager WHERE status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (empty($managerIds)) return;

        $stmt = $this->con_league->prepare(
            "INSERT IGNORE INTO manager_achievement (id, manager_id, achievement_id)
             VALUES (UUID(), ?, ?)"
        );

        foreach ($achievements as $achievement) {
            $method = 'check_' . $achievement['condition_key'];
            if (!method_exists($this, $method)) continue;

            $earners = $this->$method($managerIds);
            foreach ($earners as $managerId) {
                $stmt->execute([$managerId, $achievement['id']]);
            }
        }
    }

    public function getManagerAchievements(string $managerId): array
    {
        $rows = $this->con->query(
            "SELECT id, condition_key, name, description, icon, sort_index
             FROM achievement ORDER BY sort_index ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) return [];

        $achievementIds = array_column($rows, 'id');
        $plh = implode(',', array_fill(0, count($achievementIds), '?'));
        $earned = $this->con_league->prepare(
            "SELECT achievement_id, earned_at FROM manager_achievement
             WHERE manager_id = ? AND achievement_id IN ($plh)"
        );
        $earned->execute([$managerId, ...$achievementIds]);
        $earnedMap = [];
        foreach ($earned->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $earnedMap[$row['achievement_id']] = $row['earned_at'];
        }

        return array_map(function ($a) use ($earnedMap) {
            return [
                'id'          => $a['id'],
                'name'        => $a['name'],
                'description' => $a['description'],
                'icon'        => $a['icon'],
                'sort_index'  => (int) $a['sort_index'],
                'earned_at'   => $earnedMap[$a['id']] ?? null,
            ];
        }, $rows);
    }
}
