<?php

class SessionController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'admin'];

    private const ALLOWED_RANGES = ['today', 'day', 'month', 'year', 'all'];
    private const ALLOWED_PROFILES = ['hour', 'weekday'];

    protected function get(): mixed
    {
        $range = $this->params['range'] ?? ($this->id === 'devices' ? 'month' : 'day');
        if (!in_array($range, self::ALLOWED_RANGES, true)) {
            $range = 'day';
        }
        if ($this->id === 'devices') {
            return $this->db->getSessionDevices($range);
        }
        // Profil: derselbe Zeitraum, aber nach Tageszeit bzw. Wochentag aufsummiert — nur über längere Fenster
        $profile = $this->params['profile'] ?? null;
        if (!in_array($profile, self::ALLOWED_PROFILES, true)) {
            $profile = null;
        } elseif (!in_array($range, ['month', 'year', 'all'], true)) {
            $range = 'month';
        }
        return $this->db->getSessionHeatmap($range, $profile);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
