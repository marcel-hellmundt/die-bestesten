<?php

class SessionController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'admin'];

    private const ALLOWED_RANGES = ['today', 'day', 'month', 'weeks', 'months', 'year', 'all', 'custom'];
    private const ALLOWED_PROFILES = ['hour', 'weekday'];
    /** Längster wählbarer eigener Zeitraum (Tage, beide Grenzen eingeschlossen) */
    private const CUSTOM_MAX_DAYS = 366;

    protected function get(): mixed
    {
        $range = $this->params['range'] ?? ($this->id === 'devices' ? 'month' : 'day');
        if (!in_array($range, self::ALLOWED_RANGES, true)) {
            $range = 'day';
        }
        // Eigener Zeitraum: from/to (YYYY-MM-DD, beide eingeschlossen), tageweise
        $window = null;
        if ($range === 'custom') {
            $from = $this->parseDate($this->params['from'] ?? null);
            $to   = $this->parseDate($this->params['to'] ?? null);
            if ($from === null || $to === null || $from > $to) {
                http_response_code(400);
                return ['status' => false, 'message' => 'from und to (YYYY-MM-DD, from ≤ to) erforderlich'];
            }
            if ($from->diff($to)->days + 1 > self::CUSTOM_MAX_DAYS) {
                http_response_code(422);
                return ['status' => false, 'message' => 'Zeitraum höchstens ' . self::CUSTOM_MAX_DAYS . ' Tage'];
            }
            $window = [$from, (clone $to)->modify('+1 day')];
        }
        if ($this->id === 'devices') {
            return $this->db->getSessionDevices($range, $window);
        }
        // Profil: derselbe Zeitraum, aber nach Tageszeit bzw. Wochentag aufsummiert — nur über längere Fenster
        $profile = $this->params['profile'] ?? null;
        if (!in_array($profile, self::ALLOWED_PROFILES, true)) {
            $profile = null;
        } elseif (!in_array($range, ['month', 'year', 'all'], true)) {
            $range = 'month';
            $window = null;
        }
        return $this->db->getSessionHeatmap($range, $profile, $window);
    }

    /** Y-m-d → DateTime (0 Uhr) oder null, wenn kein gültiges Datum */
    private function parseDate(?string $value): ?DateTime
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        $d = DateTime::createFromFormat('!Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value ? $d : null;
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
