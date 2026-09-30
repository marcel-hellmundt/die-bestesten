<?php

trait SessionTrait
{
    /**
     * Approximate session-duration heartbeat. Called on every authenticated request
     * (Guard::authorize()). Extends the manager's most recent session if it ended less than
     * 2 minutes ago AND the request comes from the same device (device_type/os/browser aus dem
     * User-Agent), otherwise starts a new one. Verhindert, dass z.B. ein schneller Wechsel von
     * Handy auf Desktop die mobile Session weiterführt, nur weil die Lücke klein war — ein
     * Geräte-/Browser-Wechsel beendet die vorherige Session immer, unabhängig vom Zeitabstand.
     * Uses SELECT-then-branch rather than relying on UPDATE's affected-row count, since ended_at
     * can legitimately already equal NOW() (same second) — see setPlayerPhotoUploaded() for the
     * same rowCount() pitfall elsewhere.
     * DISABLE_SESSION_TRACKING=true (nur im .env des jeweiligen Servers gesetzt, nicht committet)
     * schaltet das Tracking komplett ab — für den Dev-Server, damit Test-/Entwickler-Traffic nicht
     * in den Nutzungs-Heatmap-Report (GET /session) einfließt.
     * Der SELECT-dann-INSERT/UPDATE-Ablauf ist nicht atomar — feuert das Frontend beim Laden
     * mehrere authentifizierte Requests parallel ab (z.B. nav.component.ts's ensureMyTeam() /
     * ensureH2HStatus() / ensureLeague() im selben Constructor), können zwei touchSession()-Aufrufe
     * gleichzeitig in getrennten PHP-Prozessen den SELECT ausführen, bevor einer von beiden seinen
     * INSERT geschrieben hat — Ergebnis: zwei Zeilen mit identischem started_at/ended_at (0s Dauer),
     * die mergeIntervals() später zu einem einzigen Punkt zusammenfasst und dadurch die komplette
     * Nutzungsdauer dieses Bursts verschluckt. Ein MySQL Advisory Lock pro Manager serialisiert
     * konkurrierende Aufrufe für denselben Manager, ohne andere Manager oder den Rest des Requests
     * zu blockieren.
     */
    public function touchSession(string $managerId): void
    {
        if (($_ENV['DISABLE_SESSION_TRACKING'] ?? '') === 'true') {
            return;
        }

        $lockName = 'manager_session_' . $managerId;
        $lockQ = $this->con->prepare('SELECT GET_LOCK(?, 2)');
        $lockQ->execute([$lockName]);
        if (!$lockQ->fetchColumn()) {
            // Lock nicht innerhalb 2s bekommen — Heartbeat für diesen Request überspringen statt
            // die Anfrage zu blockieren; der nächste Request des Managers holt es nach.
            return;
        }

        try {
            [$deviceType, $os, $browser] = $this->parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');

            $find = $this->con->prepare(
                "SELECT id, device_type, os, browser FROM manager_session
                 WHERE manager_id = :id AND ended_at >= (NOW() - INTERVAL 2 MINUTE)
                 ORDER BY ended_at DESC LIMIT 1"
            );
            $find->execute([':id' => $managerId]);
            $open = $find->fetch(PDO::FETCH_ASSOC);

            $sameDevice = $open
                && $open['device_type'] === $deviceType
                && $open['os'] === $os
                && $open['browser'] === $browser;

            if ($sameDevice) {
                $sessionId = $open['id'];
                $this->con->prepare(
                    "UPDATE manager_session SET ended_at = NOW() WHERE id = :id"
                )->execute([':id' => $sessionId]);
            } else {
                // Eine neue Session wird eröffnet — der ideale Zeitpunkt, um alte 0s-Zeilen
                // (started_at = ended_at) desselben Managers zu entsorgen, die außerhalb des
                // 2-Minuten-Fensters liegen: sie können laut obiger $find-Query nie wieder
                // verlängert werden, sind also endgültig tot. So bleibt die Tabelle ohne
                // separaten Cron-Job sauber — jeder wiederkehrende Manager räumt beim nächsten
                // Besuch automatisch seinen eigenen alten Leerlauf-Müll weg.
                $this->con->prepare(
                    "DELETE FROM manager_session
                     WHERE manager_id = :id AND started_at = ended_at
                       AND ended_at < (NOW() - INTERVAL 2 MINUTE)"
                )->execute([':id' => $managerId]);

                $sessionId = $this->con->query('SELECT UUID()')->fetchColumn();
                $this->con->prepare(
                    "INSERT INTO manager_session (id, manager_id, device_type, os, browser)
                     VALUES (:sid, :id, :device_type, :os, :browser)"
                )->execute([
                    ':sid'         => $sessionId,
                    ':id'          => $managerId,
                    ':device_type' => $deviceType,
                    ':os'          => $os,
                    ':browser'     => $browser,
                ]);
            }

            // Eigener try: fehlen die Spalten noch (Migration 2026-09-30_manager_session_client_info
            // nicht ausgeführt), läuft der Heartbeat oben trotzdem unverändert weiter.
            try {
                $this->storeSessionClientInfo($sessionId);
            } catch (Throwable) {
            }
        } finally {
            $this->con->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    /**
     * Header X-Client-Info (vom Frontend an jeden API-Request gehängt, siehe core/client-info.service.ts),
     * URL-kodiert: theme (Wahl light|dark|system), shown (angezeigtes Theme), scheme (Systemeinstellung
     * des Geräts), pwa (1 = als App installiert), osv (OS-Version), model (Gerätemodell, nur Android per
     * Client Hints), screen (kurze×lange Seite@Pixeldichte). Jeder Wert wird streng geprüft — ungültig
     * oder fehlend = NULL. Überschreibt die Werte der Session bei jedem Heartbeat (letzter Stand zählt);
     * fehlt der Header ganz (älterer Client), bleibt die Zeile unverändert.
     */
    private function storeSessionClientInfo(string $sessionId): void
    {
        $raw = $_SERVER['HTTP_X_CLIENT_INFO'] ?? '';
        if ($raw === '' || strlen($raw) > 300) {
            return;
        }
        parse_str($raw, $info);

        $pick = fn(string $key, string $pattern) =>
            isset($info[$key]) && is_string($info[$key]) && preg_match($pattern, $info[$key]) ? $info[$key] : null;

        $this->con->prepare(
            "UPDATE manager_session
             SET theme_pref = :theme_pref, theme = :theme, system_theme = :system_theme, standalone = :standalone,
                 os_version = :os_version, device_model = :device_model, screen = :screen
             WHERE id = :id"
        )->execute([
            ':id'           => $sessionId,
            ':theme_pref'   => $pick('theme', '/^(light|dark|system)$/'),
            ':theme'        => $pick('shown', '/^(light|dark)$/'),
            ':system_theme' => $pick('scheme', '/^(light|dark)$/'),
            ':standalone'   => ($pwa = $pick('pwa', '/^[01]$/')) === null ? null : (int) $pwa,
            ':os_version'   => $pick('osv', '/^\d{1,3}(\.\d{1,3}){0,2}$/'),
            ':device_model' => $pick('model', '/^[\w .()+\-]{1,40}$/u'),
            ':screen'       => $pick('screen', '/^\d{2,5}x\d{2,5}@\d(\.\d{1,2})?$/'),
        ]);
    }

    /**
     * Geräte je Manager im Zeitraum (gleiche Fenster wie getSessionHeatmap) — für "Geräte & Erscheinung"
     * auf /daten/nutzung. Ein Gerät = gleiche Kombination aus device_type, os, browser, device_model und
     * screen (keine echte Geräte-ID: zwei gleiche iPhones eines Managers sind eine Zeile). Die übrigen
     * Felder (Theme, installiert, OS-Version) kommen aus der jüngsten Session des Geräts, die sie gesetzt
     * hat. seconds = Summe der Session-Dauern dieses Geräts (nicht gemergt — pro Gerät gibt es keine
     * Überlappung).
     * Nur Sessions mit Geräte-Infos (theme_pref gesetzt = Header X-Client-Info vom aktuellen Client):
     * ältere Sessions haben z.B. kein screen und würden dasselbe Handy sonst als zweites Gerät zeigen.
     */
    public function getSessionDevices(string $range = 'month'): array
    {
        $sinceExpr = match ($range) {
            'day'   => "(NOW() - INTERVAL 24 HOUR)",
            'year'  => "(CURDATE() - INTERVAL 51 WEEK)",
            'all'   => null,
            default => "(CURDATE() - INTERVAL 29 DAY)",
        };
        $whereSql = "m.status != 'deleted' AND ms.theme_pref IS NOT NULL"
            . ($sinceExpr !== null ? " AND ms.ended_at >= $sinceExpr" : '');

        $q = $this->con->prepare(
            "SELECT ms.manager_id, m.manager_name, ms.device_type, ms.os, ms.browser, ms.started_at, ms.ended_at,
                    ms.theme_pref, ms.theme, ms.system_theme, ms.standalone, ms.os_version, ms.device_model, ms.screen
             FROM manager_session ms JOIN manager m ON m.id = ms.manager_id
             WHERE $whereSql ORDER BY ms.ended_at ASC"
        );
        $q->execute();

        $clientFields = ['theme_pref', 'theme', 'system_theme', 'standalone', 'os_version', 'device_model', 'screen'];
        $managers = [];
        // Aufsteigend nach ended_at → spätere Sessions überschreiben die Zusatzfelder (jüngster Stand)
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mid = $r['manager_id'];
            $managers[$mid] ??= ['manager_id' => $mid, 'manager_name' => $r['manager_name'], 'devices' => []];

            $key = implode('|', [$r['device_type'], $r['os'], $r['browser'], $r['device_model'] ?? '', $r['screen'] ?? '']);
            $device = &$managers[$mid]['devices'][$key];
            $device ??= [
                'device_type' => $r['device_type'], 'os' => $r['os'], 'browser' => $r['browser'],
                'sessions' => 0, 'seconds' => 0, 'last_seen' => null,
            ] + array_fill_keys($clientFields, null);

            $device['sessions']++;
            $device['seconds']  += max(0, strtotime($r['ended_at']) - strtotime($r['started_at']));
            $device['last_seen'] = $r['ended_at'];
            foreach ($clientFields as $f) {
                if (($r[$f] ?? null) !== null) {
                    $device[$f] = $f === 'standalone' ? (bool) $r[$f] : $r[$f];
                }
            }
            unset($device);
        }

        $result = [];
        foreach ($managers as $m) {
            $devices = array_values($m['devices']);
            usort($devices, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
            $m['devices'] = $devices;
            $result[] = $m;
        }
        usort($result, fn($a, $b) =>
            array_sum(array_column($b['devices'], 'seconds')) <=> array_sum(array_column($a['devices'], 'seconds'))
            ?: strcmp($a['manager_name'], $b['manager_name']));

        return ['range' => $range, 'managers' => $result];
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
     *  - 'month' → letzte 30 Tage, ein Bucket pro Tag (Schlüssel "YYYY-MM-DD")
     *  - 'year'  → letzte 52 Wochen, ein Bucket pro Woche (Schlüssel = Montag der Woche, "YYYY-MM-DD")
     *  - 'all'   → seit der allerersten Session (kein $sinceExpr), ein Bucket pro Monat
     *              (Schlüssel = 1. des Monats, "YYYY-MM-DD")
     * $range ist auf diese vier festen Werte beschränkt (switch-Default 'day') und fließt nie
     * ungeprüft in SQL ein — kein Injection-Vektor trotz String-Interpolation von $sinceExpr.
     * Pro Manager werden die Session-Intervalle zunächst gemergt (mergeIntervals) — nutzt ein
     * Manager z.B. gleichzeitig Handy und Desktop, entstehen zwei sich überlappende
     * manager_session-Zeilen (device-Wechsel, siehe touchSession()); ohne Merge würde die
     * überlappende Zeit doppelt gezählt (25min + 20min = 45min statt real 30min Wanduhrzeit).
     * Erst die gemergten, nicht-überlappenden Intervalle werden auf die tatsächlich überspannten
     * Bucket-Grenzen aufgeteilt (splitSessionIntoBuckets) statt komplett dem Bucket von
     * started_at zugeschlagen — eine per Heartbeat über Stunden-/Tagesgrenzen hinweg verlängerte
     * Session würde sonst ihre gesamte Dauer in einem einzigen Bucket zeigen.
     * Zusätzlich zu `buckets` (Gesamtsekunden, geräteübergreifend gemergt) liefert jeder Manager
     * `mobile_seconds` und `desktop_seconds` — dieselbe Bucket-Aufteilung, aber jeweils nur für
     * Intervalle der einen Gerätekategorie, separat gemerged (Tablet zählt als Mobile; unbekannter
     * device_type als Desktop). Frontend bildet daraus einen Mobile-Anteil
     * mobile_seconds / (mobile_seconds + desktop_seconds) für die Färbung nach Gerätemix — bewusst
     * NICHT gegen `buckets` (den geräteübergreifend deduplizierten Gesamtwert), da dieser bei
     * gleichzeitiger Mehrgeräte-Nutzung kleiner ist als die Summe der Einzelgeräte-Zeiten und den
     * Mobile-Anteil sonst künstlich auf bis zu 100% hochziehen würde, obwohl auch Desktop parallel
     * lief (Beispiel: Mobile 10:00–10:30, zeitgleich Desktop 10:00–10:15 → buckets=30min,
     * mobile_seconds=30min, desktop_seconds=15min; Anteil = 30/(30+15) = 66,7% statt fälschlich
     * 30/30 = 100%). mobile_seconds + desktop_seconds ist dadurch immer ≥ buckets, nie kleiner —
     * die Opacity/Dauer-Anzeige (die weiterhin `buckets` nutzt) bleibt davon unberührt.
     */
    public function getSessionHeatmap(string $range = 'day'): array
    {
        switch ($range) {
            case 'month':
                $sinceExpr = "(CURDATE() - INTERVAL 29 DAY)";
                break;
            case 'year':
                $sinceExpr = "(CURDATE() - INTERVAL 51 WEEK)";
                break;
            case 'all':
                $sinceExpr = null; // kein unteres Limit — seit der allerersten Session
                break;
            case 'day':
            default:
                $range     = 'day';
                $sinceExpr = "(NOW() - INTERVAL 24 HOUR)";
                break;
        }

        // Filter auf ended_at statt started_at, damit Sessions, die vor dem Fenster begonnen
        // haben und hineinragen, nicht komplett verloren gehen (ihr Anteil im Fenster wird beim
        // Splitten unten ohnehin auf die passenden Buckets begrenzt).
        $whereSql = "m.status != 'deleted'";
        if ($sinceExpr !== null) {
            $whereSql .= " AND ms.ended_at >= $sinceExpr";
        }

        $q = $this->con->prepare(
            "SELECT ms.manager_id, m.manager_name, m.alias, ms.device_type, ms.started_at, ms.ended_at
             FROM manager_session ms
             JOIN manager m ON m.id = ms.manager_id
             WHERE $whereSql
             ORDER BY m.manager_name ASC"
        );
        $q->execute();

        $managers = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($managers[$r['manager_id']])) {
                $managers[$r['manager_id']] = [
                    'manager_id'       => $r['manager_id'],
                    'manager_name'     => $r['manager_name'],
                    'alias'            => $r['alias'],
                    'intervals'        => [],
                    'mobileIntervals'  => [],
                    'desktopIntervals' => [],
                ];
            }

            $interval = [new DateTime($r['started_at']), new DateTime($r['ended_at'])];
            $managers[$r['manager_id']]['intervals'][] = $interval;
            if (in_array($r['device_type'], ['mobile', 'tablet'], true)) {
                $managers[$r['manager_id']]['mobileIntervals'][] = $interval;
            } else {
                $managers[$r['manager_id']]['desktopIntervals'][] = $interval;
            }
        }

        $result = [];
        foreach ($managers as $manager) {
            $buckets = $this->bucketizeIntervals($manager['intervals'], $range);

            $result[] = [
                'manager_id'      => $manager['manager_id'],
                'manager_name'    => $manager['manager_name'],
                'alias'           => $manager['alias'],
                'buckets'         => $buckets,
                'mobile_seconds'  => $this->bucketizeIntervals($manager['mobileIntervals'], $range),
                'desktop_seconds' => $this->bucketizeIntervals($manager['desktopIntervals'], $range),
                '_total'          => array_sum($buckets),
            ];
        }

        // Absteigend nach Gesamtnutzung im Zeitraum, bei Gleichstand alphabetisch als stabiler
        // Tiebreaker (statt Zufallsreihenfolge durch array-Iteration).
        usort($result, fn($a, $b) => $b['_total'] <=> $a['_total'] ?: strcmp($a['manager_name'], $b['manager_name']));
        foreach ($result as &$r) unset($r['_total']);
        unset($r);

        // Gerade online: letzter Heartbeat (ended_at wird bei jedem authentifizierten Request verlängert,
        // das Frontend pollt alle 4 s) vor weniger als 3 Minuten — unabhängig vom gewählten Zeitraum
        // idle_seconds = Sekunden seit dem letzten Heartbeat (Frontend: < 60 s voller Punkt, sonst Ring)
        $oq = $this->con->prepare(
            "SELECT ms.manager_id, GREATEST(0, TIMESTAMPDIFF(SECOND, MAX(ms.ended_at), NOW())) AS idle_seconds
             FROM manager_session ms
             JOIN manager m ON m.id = ms.manager_id
             WHERE m.status != 'deleted' AND ms.ended_at >= (NOW() - INTERVAL 3 MINUTE)
             GROUP BY ms.manager_id"
        );
        $oq->execute();
        $online = array_map(
            fn($r) => ['manager_id' => $r['manager_id'], 'idle_seconds' => (int) $r['idle_seconds']],
            $oq->fetchAll(PDO::FETCH_ASSOC),
        );

        return ['range' => $range, 'managers' => $result, 'online_managers' => $online];
    }

    /** Merged $intervals (siehe mergeIntervals) und summiert sie pro Bucket (siehe splitSessionIntoBuckets). */
    private function bucketizeIntervals(array $intervals, string $range): array
    {
        $buckets = [];
        foreach ($this->mergeIntervals($intervals) as [$start, $end]) {
            foreach ($this->splitSessionIntoBuckets($start, $end, $range) as $bucketKey => $seconds) {
                $buckets[$bucketKey] = ($buckets[$bucketKey] ?? 0) + $seconds;
            }
        }
        return $buckets;
    }

    /**
     * Merged überlappende/berührende [start, end]-Intervalle zu disjunkten Intervallen (klassischer
     * Sweep nach Sortierung). Grundlage dafür, dass gleichzeitige Nutzung auf mehreren Geräten nur
     * einmal gezählt wird.
     */
    private function mergeIntervals(array $intervals): array
    {
        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                if ($end > $merged[$last][1]) {
                    $merged[$last][1] = $end;
                }
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
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
            case 'all':
                $monthStart = (clone $t)->setDate((int) $t->format('Y'), (int) $t->format('n'), 1)->setTime(0, 0, 0);
                $end        = (clone $monthStart)->modify('+1 month');
                return [$monthStart->format('Y-m-d'), $end];
            case 'month':
            default:
                $dayStart = (clone $t)->setTime(0, 0, 0);
                $end      = (clone $dayStart)->modify('+1 day');
                return [$dayStart->format('Y-m-d'), $end];
        }
    }
}
