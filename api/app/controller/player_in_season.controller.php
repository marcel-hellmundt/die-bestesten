<?php

class PlayerInSeasonController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'maintainer', 'PATCH' => 'maintainer'];

    protected function get(): mixed
    {
        if ($this->id === 'bundesliga_count') {
            $seasonId = $this->params['season_id'] ?? null;
            return ['count' => $this->db->getLeaguePlayerCount($seasonId)];
        }

        if ($this->id === 'available_players') {
            $seasonId = $this->params['season_id'] ?? null;
            return $this->db->getAvailablePlayers($seasonId);
        }

        http_response_code(400);
        return ['status' => false, 'message' => 'Unknown sub-resource'];
    }

    protected function post(): mixed
    {
        if ($this->id === 'preview_csv') return $this->previewCsv();
        if ($this->id === 'import_csv')  return $this->importCsv();

        $body     = $this->body();
        $playerId = $body['player_id'] ?? null;
        $seasonId = $body['season_id'] ?? null;
        $position = $body['position']  ?? null;
        $price    = isset($body['price']) ? (int) $body['price'] : null;

        $validPositions = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];

        if (!$playerId || !$seasonId || !$position || !$price || $price <= 0) {
            http_response_code(400);
            return ['status' => false, 'message' => 'player_id, season_id, position and price (> 0) required'];
        }

        if (!in_array($position, $validPositions, true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Invalid position'];
        }

        $id = $this->generateGUID();
        try {
            $this->db->createPlayerInSeason($id, $playerId, $seasonId, $position, $price);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                http_response_code(409);
                return ['status' => false, 'message' => 'Spieler hat bereits einen Eintrag für diese Saison'];
            }
            throw $e;
        }

        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }

    private function previewCsv(): mixed
    {
        if (!$this->isMaintainer()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Nur Maintainer dürfen CSV-Imports durchführen'];
        }

        $file       = $_FILES['csv']['tmp_name'] ?? null;
        $divisionId = $_POST['division_id'] ?? null; // optional — omitted triggers auto-detection
        if (!$file || !is_readable($file)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'CSV-Datei fehlt'];
        }

        try {
            $result = $this->db->previewCsvImport($file, $divisionId);
        } catch (RuntimeException $e) {
            http_response_code(422);
            return ['status' => false, 'message' => $e->getMessage()];
        }

        return ['status' => true, ...$result];
    }

    private function importCsv(): mixed
    {
        if (!$this->isMaintainer()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Nur Maintainer dürfen CSV-Imports durchführen'];
        }

        $rows = $this->body()['rows'] ?? null;
        if (!is_array($rows) || empty($rows)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'rows[] erforderlich'];
        }

        try {
            $result = $this->db->importCsvRows($rows);
        } catch (RuntimeException $e) {
            http_response_code(422);
            return ['status' => false, 'message' => $e->getMessage()];
        }

        http_response_code(201);
        return ['status' => true, ...$result];
    }

    protected function patch(): mixed
    {
        $body     = $this->body();
        $position = $body['position'] ?? null;
        $price    = isset($body['price']) ? (int) $body['price'] : null;

        if ($position === null && $price === null) {
            http_response_code(400);
            return ['status' => false, 'message' => 'position oder price erforderlich'];
        }

        $validPositions = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];
        if ($position !== null && !in_array($position, $validPositions, true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Invalid position'];
        }
        if ($price !== null && $price <= 0) {
            http_response_code(400);
            return ['status' => false, 'message' => 'price muss > 0 sein'];
        }

        if (!$this->db->updatePlayerInSeason($this->id, $position, $price)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'player_in_season nicht gefunden'];
        }

        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
