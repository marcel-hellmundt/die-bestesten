<?php

trait SessionTrait
{
    /**
     * Approximate session-duration heartbeat. Called on every authenticated request
     * (Guard::authorize()). Extends the manager's most recent session if it ended less than
     * 3 minutes ago, otherwise starts a new one. Uses SELECT-then-branch rather than relying on
     * UPDATE's affected-row count, since ended_at can legitimately already equal NOW() (same
     * second) — see setPlayerPhotoUploaded() for the same rowCount() pitfall elsewhere.
     */
    public function touchSession(string $managerId): void
    {
        $find = $this->con->prepare(
            "SELECT id FROM manager_session
             WHERE manager_id = :id AND ended_at >= (NOW() - INTERVAL 3 MINUTE)
             ORDER BY ended_at DESC LIMIT 1"
        );
        $find->execute([':id' => $managerId]);
        $openId = $find->fetchColumn();

        if ($openId) {
            $this->con->prepare(
                "UPDATE manager_session SET ended_at = NOW() WHERE id = :id"
            )->execute([':id' => $openId]);
        } else {
            $this->con->prepare(
                "INSERT INTO manager_session (manager_id) VALUES (:id)"
            )->execute([':id' => $managerId]);
        }
    }

    /**
     * Daily usage seconds per manager for the last $days days (heatmap raw data). Sessions are
     * bucketed by their start day in server-local time.
     */
    public function getSessionHeatmap(int $days = 7): array
    {
        $q = $this->con->prepare(
            "SELECT ms.manager_id, m.manager_name, m.alias,
                    DATE(ms.started_at) AS day,
                    SUM(TIMESTAMPDIFF(SECOND, ms.started_at, ms.ended_at)) AS seconds
             FROM manager_session ms
             JOIN manager m ON m.id = ms.manager_id
             WHERE ms.started_at >= (CURDATE() - INTERVAL :days DAY)
               AND m.status != 'deleted'
             GROUP BY ms.manager_id, m.manager_name, m.alias, DATE(ms.started_at)
             ORDER BY m.manager_name ASC, day ASC"
        );
        $q->bindValue(':days', $days, PDO::PARAM_INT);
        $q->execute();

        $managers = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($managers[$r['manager_id']])) {
                $managers[$r['manager_id']] = [
                    'manager_id'   => $r['manager_id'],
                    'manager_name' => $r['manager_name'],
                    'alias'        => $r['alias'],
                    'days'         => [],
                ];
            }
            $managers[$r['manager_id']]['days'][$r['day']] = (int) $r['seconds'];
        }

        return ['days' => $days, 'managers' => array_values($managers)];
    }
}
