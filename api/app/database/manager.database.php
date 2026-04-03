<?php

trait ManagerTrait
{
    public function getManagerById(string $id): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT id, manager_name, alias, role, status, email FROM manager WHERE id = :id LIMIT 1"
        );
        $q->execute([':id' => $id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function getManagerWithTeams(string $id): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT id, manager_name, alias, role, status FROM manager WHERE id = :id LIMIT 1"
        );
        $q->execute([':id' => $id]);
        $manager = $q->fetch(PDO::FETCH_ASSOC);
        if (!$manager) return false;

        $q = $this->con_league->prepare(
            "SELECT t.id, t.season_id, t.team_name, t.color,
                    COALESCE(SUM(tr.points), 0) AS total_points,
                    COUNT(CASE WHEN tr.id IS NOT NULL AND tr.invalid = 0 THEN 1 END) AS matchdays_played
             FROM team t
             LEFT JOIN team_rating tr ON tr.team_id = t.id
             WHERE t.manager_id = :manager_id
             GROUP BY t.id, t.season_id, t.team_name, t.color
             ORDER BY t.season_id DESC"
        );
        $q->execute([':manager_id' => $id]);
        $manager['teams'] = $q->fetchAll(PDO::FETCH_ASSOC);

        return $manager;
    }

    public function getTeamById(string $id): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT t.id, t.season_id, t.team_name, t.color,
                    t.manager_id, m.manager_name, m.alias,
                    COALESCE(SUM(tr.points), 0) AS total_points,
                    COUNT(CASE WHEN tr.id IS NOT NULL AND tr.invalid = 0 THEN 1 END) AS matchdays_played
             FROM team t
             JOIN manager m ON m.id = t.manager_id
             LEFT JOIN team_rating tr ON tr.team_id = t.id
             WHERE t.id = :id
             GROUP BY t.id, t.season_id, t.team_name, t.color, t.manager_id, m.manager_name, m.alias
             LIMIT 1"
        );
        $q->execute([':id' => $id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function getTeamRatings(string $teamId): array
    {
        $q = $this->con_league->prepare(
            "SELECT tr.id, tr.matchday_id, tr.points, tr.max_points,
                    tr.goals, tr.assists, tr.clean_sheet, tr.sds,
                    tr.sds_defender, tr.missed_goals, tr.invalid
             FROM team_rating tr
             WHERE tr.team_id = :team_id"
        );
        $q->execute([':team_id' => $teamId]);
        $ratings = $q->fetchAll(PDO::FETCH_ASSOC);

        if (empty($ratings)) return [];

        $matchdayIds  = array_column($ratings, 'matchday_id');
        $placeholders = implode(',', array_fill(0, count($matchdayIds), '?'));
        $mq = $this->con->prepare(
            "SELECT id, number, kickoff_date FROM matchday WHERE id IN ($placeholders)"
        );
        $mq->execute($matchdayIds);
        $matchdayMap = array_column($mq->fetchAll(PDO::FETCH_ASSOC), null, 'id');

        foreach ($ratings as &$r) {
            $md = $matchdayMap[$r['matchday_id']] ?? null;
            $r['matchday_number'] = $md ? (int)$md['number'] : null;
            $r['kickoff_date']    = $md ? $md['kickoff_date'] : null;
        }
        unset($r);

        usort($ratings, fn($a, $b) => ($a['matchday_number'] ?? 0) <=> ($b['matchday_number'] ?? 0));

        return $ratings;
    }

    public function updateManagerPassword(string $id, string $hashedPassword): bool
    {
        $q = $this->con_league->prepare(
            "UPDATE manager SET password = :password WHERE id = :id"
        );
        $q->execute([':password' => $hashedPassword, ':id' => $id]);
        return $q->rowCount() > 0;
    }

    public function updateManagerEmail(string $id, ?string $email): bool
    {
        $q = $this->con_league->prepare(
            "UPDATE manager SET email = :email WHERE id = :id"
        );
        $q->execute([':email' => $email, ':id' => $id]);
        return true;
    }

    public function markManagerDeleted(string $id): void
    {
        $q = $this->con_league->prepare(
            "UPDATE manager SET status = 'deleted' WHERE id = :id"
        );
        $q->execute([':id' => $id]);
    }
}
