<?php

trait AchievementTrait
{
    public function evaluateAchievements(): void
    {
        $achievements = $this->con->query(
            "SELECT id, condition_key FROM achievement"
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

    public function evaluateAchievementById(string $achievementId): void
    {
        $achievement = $this->con->prepare(
            "SELECT id, condition_key FROM achievement WHERE id = ?"
        );
        $achievement->execute([$achievementId]);
        $achievement = $achievement->fetch(PDO::FETCH_ASSOC);

        if (!$achievement) return;

        $managerIds = $this->con_league->query(
            "SELECT id FROM manager WHERE status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $method  = 'check_' . $achievement['condition_key'];
        $earners = method_exists($this, $method) ? $this->$method($managerIds) : [];

        if (empty($earners)) {
            $this->con_league->prepare(
                "DELETE FROM manager_achievement WHERE achievement_id = ?"
            )->execute([$achievementId]);
            return;
        }

        $plh = implode(',', array_fill(0, count($earners), '?'));
        $this->con_league->prepare(
            "DELETE FROM manager_achievement WHERE achievement_id = ? AND manager_id NOT IN ($plh)"
        )->execute([$achievementId, ...$earners]);

        $stmt = $this->con_league->prepare(
            "INSERT IGNORE INTO manager_achievement (id, manager_id, achievement_id) VALUES (UUID(), ?, ?)"
        );
        foreach ($earners as $managerId) {
            $stmt->execute([$managerId, $achievementId]);
        }
    }

    public function getAllAchievementsAdmin(): array
    {
        $achievements = $this->con->query(
            "SELECT id, condition_key, name, description, icon FROM achievement"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($achievements)) return [];

        $managers = $this->con_league->query(
            "SELECT id, manager_name FROM manager WHERE status = 'active' ORDER BY manager_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $totalManagers = count($managers);

        $earned = $this->con_league->query(
            "SELECT manager_id, achievement_id, earned_at FROM manager_achievement"
        )->fetchAll(PDO::FETCH_ASSOC);

        $earnedMap = [];
        foreach ($earned as $row) {
            $earnedMap[$row['achievement_id']][$row['manager_id']] = $row['earned_at'];
        }

        $result = array_map(function ($a) use ($managers, $earnedMap, $totalManagers) {
            $achievementEarned = $earnedMap[$a['id']] ?? [];

            $managerList = array_map(function ($m) use ($achievementEarned) {
                return [
                    'id'           => $m['id'],
                    'manager_name' => $m['manager_name'],
                    'earned_at'    => $achievementEarned[$m['id']] ?? null,
                ];
            }, $managers);

            return [
                'id'             => $a['id'],
                'condition_key'  => $a['condition_key'],
                'name'           => $a['name'],
                'description'    => $a['description'],
                'icon'           => $a['icon'],
                'earned_count'   => count($achievementEarned),
                'total_managers' => $totalManagers,
                'managers'       => $managerList,
            ];
        }, $achievements);

        usort($result, fn($a, $b) => $b['earned_count'] - $a['earned_count']);
        return $result;
    }

    public function getManagerAchievements(string $managerId): array
    {
        $rows = $this->con->query(
            "SELECT id, name, description, icon FROM achievement"
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

        $counts = $this->con_league->query(
            "SELECT achievement_id, COUNT(*) AS cnt FROM manager_achievement GROUP BY achievement_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $result = array_map(function ($a) use ($earnedMap, $counts) {
            return [
                'id'          => $a['id'],
                'name'        => $a['name'],
                'description' => $a['description'],
                'icon'        => $a['icon'],
                'earned_at'   => $earnedMap[$a['id']] ?? null,
                '_count'      => (int) ($counts[$a['id']] ?? 0),
            ];
        }, $rows);

        usort($result, fn($a, $b) => $b['_count'] - $a['_count']);

        return array_map(function ($a) {
            unset($a['_count']);
            return $a;
        }, $result);
    }
}
