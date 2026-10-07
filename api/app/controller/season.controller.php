<?php

class SeasonController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin', 'PATCH' => 'admin'];

    private const LUKATEN_MODES = ['classic', 'account'];

    protected function get(): mixed
    {
        if ($this->id === 'active') {
            $season = $this->db->getActiveSeason();
            if (!$season) {
                http_response_code(404);
                return ['status' => false, 'message' => 'No active season found'];
            }
            // Lukaten-Modus (classic ohne Migration) + ob er auf dieser Umgebung umschaltbar ist — für die Verwaltung
            $season['lukaten_mode'] = $this->db->getSeasonLukatenMode($season['id']);
            $season['lukaten_mode_switchable'] = $this->lukatenModeSwitchable();
            return $season;
        }

        if ($this->id) {
            $season = $this->db->getSeasonById($this->id);
            if (!$season) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Season not found'];
            }
            return $season;
        }

        return $this->db->getSeasonList();
    }

    protected function post(): mixed
    {
        if ($this->id) return $this->methodNotAllowed();

        $body      = $this->body();
        $startDate = $body['start_date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'start_date must be YYYY-MM-DD'];
        }

        $id = $this->generateGUID();
        $this->db->createSeason($id, $startDate);
        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }
    /**
     * PATCH /season/:id {lukaten_mode: classic|account} — Lukaten-Modus der Saison umschalten (Admin). Gedacht zum
     * Ausprobieren des Konto-Modus auf der Development-Umgebung: nur möglich, wenn LUKATEN_MODE_SWITCH=true im
     * .env steht (auf Production nicht gesetzt — dort beginnt der neue Modus mit der neuen Saison).
     */
    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        $mode = $this->body()['lukaten_mode'] ?? null;
        if (!in_array($mode, self::LUKATEN_MODES, true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'lukaten_mode (classic|account) erforderlich'];
        }
        if (!$this->lukatenModeSwitchable()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Der Lukaten-Modus ist auf dieser Umgebung nicht umschaltbar'];
        }
        try {
            $found = $this->db->setSeasonLukatenMode($this->id, $mode);
        } catch (\Throwable $e) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Migration 2026-10-07_lukaten_account.sql fehlt'];
        }
        if (!$found) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Season not found'];
        }
        return ['status' => true, 'lukaten_mode' => $mode];
    }

    private function lukatenModeSwitchable(): bool
    {
        return ($_ENV['LUKATEN_MODE_SWITCH'] ?? '') === 'true';
    }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
