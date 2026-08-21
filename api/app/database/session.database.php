<?php

trait SessionTrait
{
    /**
     * Approximate session-duration heartbeat. Called on every authenticated request
     * (Guard::authorize()). Extends the manager's most recent session if it ended less than
     * 3 minutes ago, otherwise starts a new one. Uses SELECT-then-branch rather than relying on
     * UPDATE's affected-row count, since ended_at can legitimately already equal NOW() (same
     * second) — see setPlayerPhotoUploaded() for the same rowCount() pitfall elsewhere.
     * Device-Infos (device_type/os/browser) werden nur beim Anlegen einer neuen Session aus dem
     * User-Agent-Header geparst, nicht bei jeder Verlängerung — das Gerät ändert sich innerhalb
     * einer Session nicht.
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
            [$deviceType, $os, $browser] = $this->parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');
            $this->con->prepare(
                "INSERT INTO manager_session (manager_id, device_type, os, browser)
                 VALUES (:id, :device_type, :os, :browser)"
            )->execute([
                ':id'          => $managerId,
                ':device_type' => $deviceType,
                ':os'          => $os,
                ':browser'     => $browser,
            ]);
        }
    }

    /**
     * Grobe User-Agent-Erkennung ohne externe Library — deckt die gängigen Fälle ab
     * (Mobile/Tablet/Desktop, iOS/Android/Windows/macOS/Linux, gängige Browser). Liefert
     * [device_type, os, browser], jeweils null wenn nicht erkennbar (z. B. leerer/exotischer UA).
     * Reihenfolge der Browser-Checks ist wichtig: Edge/Opera enthalten "Chrome" im UA, iOS-Browser
     * enthalten "Safari" im UA, müssen also vor den generischen Chrome-/Safari-Checks geprüft werden.
     */
    private function parseUserAgent(string $ua): array
    {
        if ($ua === '') {
            return [null, null, null];
        }

        $isTablet = (bool) preg_match('/iPad|Tablet|(?=.*Android)(?!.*Mobile)/i', $ua);
        $isMobile = !$isTablet && (bool) preg_match('/Mobi|iPhone|iPod|Android/i', $ua);
        $deviceType = $isTablet ? 'tablet' : ($isMobile ? 'mobile' : 'desktop');

        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua)    => 'iOS',
            (bool) preg_match('/Android/i', $ua)             => 'Android',
            (bool) preg_match('/Windows/i', $ua)             => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua)  => 'macOS',
            (bool) preg_match('/Linux/i', $ua)               => 'Linux',
            default                                          => null,
        };

        $browser = match (true) {
            (bool) preg_match('/Edg\//i', $ua)               => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $ua)         => 'Opera',
            (bool) preg_match('/FxiOS/i', $ua)               => 'Firefox',
            (bool) preg_match('/CriOS/i', $ua)               => 'Chrome',
            (bool) preg_match('/Chrome\//i', $ua)            => 'Chrome',
            (bool) preg_match('/Firefox\//i', $ua)           => 'Firefox',
            (bool) preg_match('/Safari\//i', $ua)             => 'Safari',
            default                                          => null,
        };

        return [$deviceType, $os, $browser];
    }

    /**
     * Usage seconds per manager, bucketed for the given range (heatmap raw data):
     *  - 'day'   → letzte 24h, ein Bucket pro Stunde (Schlüssel "YYYY-MM-DDTHH:00:00")
     *  - 'week'  → letzte 7 Tage, ein Bucket pro Tag (Schlüssel "YYYY-MM-DD")
     *  - 'month' → letzte 30 Tage, ein Bucket pro Tag (Schlüssel "YYYY-MM-DD")
     *  - 'year'  → letzte 52 Wochen, ein Bucket pro Woche (Schlüssel = Montag der Woche, "YYYY-MM-DD")
     * $range ist auf diese vier festen Werte beschränkt (switch-Default 'week') und fließt nie
     * ungeprüft in SQL ein — kein Injection-Vektor trotz String-Interpolation von $sinceExpr.
     * Dauer wird pro Session in PHP auf die tatsächlich überspannten Bucket-Grenzen aufgeteilt
     * (splitSessionIntoBuckets) statt komplett dem Bucket von started_at zugeschlagen — eine per
     * Heartbeat über Stunden-/Tagesgrenzen hinweg verlängerte Session würde sonst ihre gesamte
     * Dauer in einem einzigen Bucket zeigen (z. B. "1h12min" in einer Stunden-Spalte).
     */
    public function getSessionHeatmap(string $range = 'week'): array
    {
        switch ($range) {
            case 'day':
                $sinceExpr = "(NOW() - INTERVAL 24 HOUR)";
                break;
            case 'month':
                $sinceExpr = "(CURDATE() - INTERVAL 29 DAY)";
                break;
            case 'year':
                $sinceExpr = "(CURDATE() - INTERVAL 51 WEEK)";
                break;
            case 'week':
            default:
                $range     = 'week';
                $sinceExpr = "(CURDATE() - INTERVAL 6 DAY)";
                break;
        }

        // Filter auf ended_at statt started_at, damit Sessions, die vor dem Fenster begonnen
        // haben und hineinragen, nicht komplett verloren gehen (ihr Anteil im Fenster wird beim
        // Splitten unten ohnehin auf die passenden Buckets begrenzt).
        $q = $this->con->prepare(
            "SELECT ms.manager_id, m.manager_name, m.alias, ms.started_at, ms.ended_at
             FROM manager_session ms
             JOIN manager m ON m.id = ms.manager_id
             WHERE ms.ended_at >= $sinceExpr
               AND m.status != 'deleted'
             ORDER BY m.manager_name ASC"
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

            $start = new DateTime($r['started_at']);
            $end   = new DateTime($r['ended_at']);
            foreach ($this->splitSessionIntoBuckets($start, $end, $range) as $bucketKey => $seconds) {
                $managers[$r['manager_id']]['buckets'][$bucketKey] =
                    ($managers[$r['manager_id']]['buckets'][$bucketKey] ?? 0) + $seconds;
            }
        }

        return ['range' => $range, 'managers' => array_values($managers)];
    }

    /**
     * Zerlegt [$start, $end) entlang der Bucket-Grenzen des Zeitraums und liefert
     * [bucketKey => Sekunden-Anteil in diesem Bucket]. Sorgt dafür, dass eine Session, die eine
     * Bucket-Grenze überschreitet, anteilig auf beide Buckets verteilt wird statt komplett im
     * Start-Bucket zu landen.
     */
    private function splitSessionIntoBuckets(DateTime $start, DateTime $end, string $range): array
    {
        $result = [];
        $cursor = clone $start;

        while ($cursor < $end) {
            [$bucketKey, $bucketEnd] = $this->sessionBucketBoundary($cursor, $range);
            $segmentEnd = $bucketEnd < $end ? $bucketEnd : $end;
            $seconds    = $segmentEnd->getTimestamp() - $cursor->getTimestamp();
            if ($seconds > 0) {
                $result[$bucketKey] = ($result[$bucketKey] ?? 0) + $seconds;
            }
            $cursor = $segmentEnd;
        }

        return $result;
    }

    /** Bucket-Schlüssel für $t sowie exklusives Ende des Buckets, je nach Zeitraum. */
    private function sessionBucketBoundary(DateTime $t, string $range): array
    {
        switch ($range) {
            case 'day':
                $key = $t->format('Y-m-d\TH:00:00');
                $end = (clone $t)->setTime((int) $t->format('H'), 0, 0)->modify('+1 hour');
                return [$key, $end];
            case 'year':
                $weekday = (int) $t->format('N'); // 1=Montag..7=Sonntag
                $monday  = (clone $t)->setTime(0, 0, 0)->modify('-' . ($weekday - 1) . ' days');
                $end     = (clone $monday)->modify('+1 week');
                return [$monday->format('Y-m-d'), $end];
            case 'week':
            case 'month':
            default:
                $dayStart = (clone $t)->setTime(0, 0, 0);
                $end      = (clone $dayStart)->modify('+1 day');
                return [$dayStart->format('Y-m-d'), $end];
        }
    }
}
