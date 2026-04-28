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
            "INSERT IGNORE INTO manager_achievement (id, manager_id, achievement_id, reason, earned_at, level)
             VALUES (UUID(), ?, ?, ?, ?, ?)"
        );

        foreach ($achievements as $achievement) {
            $method = 'check_' . $achievement['condition_key'];
            if (!method_exists($this, $method)) continue;

            $earners = $this->$method($managerIds);
            foreach ($earners as $managerId => $meta) {
                $stmt->execute([$managerId, $achievement['id'], $meta['reason'], $meta['earned_at'], $meta['level'] ?? 'gold']);
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

        $earnerIds = array_keys($earners);
        $plh       = implode(',', array_fill(0, count($earnerIds), '?'));
        $this->con_league->prepare(
            "DELETE FROM manager_achievement WHERE achievement_id = ? AND manager_id NOT IN ($plh)"
        )->execute([$achievementId, ...$earnerIds]);

        $stmt = $this->con_league->prepare(
            "INSERT INTO manager_achievement (id, manager_id, achievement_id, reason, earned_at, level)
             VALUES (UUID(), ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), earned_at = VALUES(earned_at), level = VALUES(level)"
        );
        foreach ($earners as $managerId => $meta) {
            $stmt->execute([$managerId, $achievementId, $meta['reason'], $meta['earned_at'], $meta['level'] ?? 'gold']);
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
            "SELECT manager_id, achievement_id, earned_at, reason, level FROM manager_achievement"
        )->fetchAll(PDO::FETCH_ASSOC);

        $earnedMap = [];
        foreach ($earned as $row) {
            $earnedMap[$row['achievement_id']][$row['manager_id']] = [
                'earned_at' => $row['earned_at'],
                'reason'    => $row['reason'],
                'level'     => $row['level'],
            ];
        }

        $result = array_map(function ($a) use ($managers, $earnedMap, $totalManagers) {
            $achievementEarned = $earnedMap[$a['id']] ?? [];

            $managerList = array_map(function ($m) use ($achievementEarned) {
                $entry = $achievementEarned[$m['id']] ?? null;
                return [
                    'id'           => $m['id'],
                    'manager_name' => $m['manager_name'],
                    'earned_at'    => $entry['earned_at'] ?? null,
                    'reason'       => $entry['reason']    ?? null,
                    'level'        => $entry['level']     ?? null,
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
            "SELECT achievement_id, earned_at, reason, seen_at, level FROM manager_achievement
             WHERE manager_id = ? AND achievement_id IN ($plh)"
        );
        $earned->execute([$managerId, ...$achievementIds]);
        $earnedMap = [];
        foreach ($earned->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $earnedMap[$row['achievement_id']] = ['earned_at' => $row['earned_at'], 'reason' => $row['reason'], 'seen_at' => $row['seen_at'], 'level' => $row['level']];
        }

        $counts = $this->con_league->query(
            "SELECT achievement_id, COUNT(*) AS cnt FROM manager_achievement GROUP BY achievement_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $totalManagers = (int) $this->con_league->query(
            "SELECT COUNT(*) FROM manager WHERE status = 'active'"
        )->fetchColumn();

        $result = array_map(function ($a) use ($earnedMap, $counts, $totalManagers) {
            $count    = (int) ($counts[$a['id']] ?? 0);
            $earned   = $earnedMap[$a['id']] ?? null;
            return [
                'id'             => $a['id'],
                'name'           => $a['name'],
                'description'    => $a['description'],
                'icon'           => $a['icon'],
                'earned_at'      => $earned['earned_at'] ?? null,
                'reason'         => $earned['reason'] ?? null,
                'seen_at'        => $earned['seen_at'] ?? null,
                'level'          => $earned['level'] ?? null,
                'earned_count'   => $count,
                'total_managers' => $totalManagers,
                '_count'         => $count,
            ];
        }, $rows);

        usort($result, fn($a, $b) => $b['_count'] - $a['_count']);

        return array_map(function ($a) {
            unset($a['_count']);
            return $a;
        }, $result);
    }

    public function markAchievementsSeen(string $managerId): void
    {
        $this->con_league->prepare(
            "UPDATE manager_achievement SET seen_at = NOW()
             WHERE manager_id = ? AND seen_at IS NULL AND earned_at IS NOT NULL"
        )->execute([$managerId]);
    }
}
