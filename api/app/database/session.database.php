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
     * Usage seconds per manager, bucketed for the given range (heatmap raw data):
     *  - 'day'   → letzte 24h, ein Bucket pro Stunde (Schlüssel "YYYY-MM-DDTHH:00:00")
     *  - 'week'  → letzte 7 Tage, ein Bucket pro Tag (Schlüssel "YYYY-MM-DD")
     *  - 'month' → letzte 30 Tage, ein Bucket pro Tag (Schlüssel "YYYY-MM-DD")
     *  - 'year'  → letzte 52 Wochen, ein Bucket pro Woche (Schlüssel = Montag der Woche, "YYYY-MM-DD")
     * $range ist auf diese vier festen Werte beschränkt (switch-Default 'week') und fließt nie
     * ungeprüft in SQL ein — kein Injection-Vektor trotz String-Interpolation der Bucket-Ausdrücke.
     */
    public function getSessionHeatmap(string $range = 'week'): array
    {
        switch ($range) {
            case 'day':
                $bucketExpr = "DATE_FORMAT(ms.started_at, '%Y-%m-%dT%H:00:00')";
                $sinceExpr  = "(NOW() - INTERVAL 24 HOUR)";
                break;
            case 'month':
                $bucketExpr = "DATE(ms.started_at)";
                $sinceExpr  = "(CURDATE() - INTERVAL 29 DAY)";
                break;
            case 'year':
                // Montag der jeweiligen Woche als Bucket-Schlüssel (WEEKDAY: 0=Montag..6=Sonntag)
                $bucketExpr = "DATE_SUB(DATE(ms.started_at), INTERVAL WEEKDAY(ms.started_at) DAY)";
                $sinceExpr  = "(CURDATE() - INTERVAL 51 WEEK)";
                break;
            case 'week':
            default:
                $range      = 'week';
                $bucketExpr = "DATE(ms.started_at)";
                $sinceExpr  = "(CURDATE() - INTERVAL 6 DAY)";
                break;
        }

        $q = $this->con->prepare(
            "SELECT ms.manager_id, m.manager_name, m.alias,
                    $bucketExpr AS bucket,
                    SUM(TIMESTAMPDIFF(SECOND, ms.started_at, ms.ended_at)) AS seconds
             FROM manager_session ms
             JOIN manager m ON m.id = ms.manager_id
             WHERE ms.started_at >= $sinceExpr
               AND m.status != 'deleted'
             GROUP BY ms.manager_id, m.manager_name, m.alias, bucket
             ORDER BY m.manager_name ASC, bucket ASC"
        );
        $q->execute();

        $managers = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($managers[$r['manager_id']])) {
                $managers[$r['manager_id']] = [
                    'manager_id'   => $r['manager_id'],
                    'manager_name' => $r['manager_name'],
                    'alias'        => $r['alias'],
                    'buckets'      => [],
                ];
            }
            $managers[$r['manager_id']]['buckets'][$r['bucket']] = (int) $r['seconds'];
        }

        return ['range' => $range, 'managers' => array_values($managers)];
    }
}
