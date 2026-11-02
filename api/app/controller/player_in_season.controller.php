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
            $seasonId   = $this->params['season_id'] ?? null;
            $includeAll = !empty($this->params['include_all']);
            return $this->db->getAvailablePlayers($seasonId, $includeAll);
        }

        http_response_code(400);
        return ['status' => false, 'message' => 'Unknown sub-resource'];
    }

    protected function post(): mixed
    {
        if ($this->id === 'preview_csv') return $this->previewCsv();
        if ($this->id === 'import_csv')  return $this->importCsv();

        $body       = $this->body();
        $playerId   = $body['player_id']   ?? null;
        $seasonId   = $body['season_id']   ?? null;
        $divisionId = $body['division_id'] ?? null;
        $position   = $body['position']    ?? null;
        // price leer/null = Mondpreis (noch kein Marktwert, nicht kaufbar); ein angegebener Wert muss plausibel sein
        $priceGiven = isset($body['price']) && $body['price'] !== '';
        $price      = $this->db->normalizeMarketValue($body['price'] ?? null);

        $validPositions = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];

        if (!$playerId || !$seasonId || !$position) {
            http_response_code(400);
            return ['status' => false, 'message' => 'player_id, season_id and position required'];
        }
        if ($priceGiven && $price === null) {
            http_response_code(400);
            return ['status' => false, 'message' => 'price muss zwischen 0 und 50.000.000 liegen (leer lassen = Mondpreis)'];
        }

        if (!in_array($position, $validPositions, true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Invalid position'];
        }

        // division_id ist optional — ohne explizite Angabe wird sie aus dem aktuellen Verein des
        // Spielers hergeleitet (Fallback: Liga-Division). Erlaubt älteren Frontend-Callern ohne
        // Divisions-Auswahl (z.B. das "Saison hinzufügen"-Formular), unverändert weiterzulaufen.
        if (!$divisionId) {
            $divisionId = $this->db->resolvePlayerCurrentDivisionId($playerId, $seasonId);
            if (!$divisionId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'division_id konnte nicht automatisch ermittelt werden — bitte explizit angeben'];
            }
        }

        $id = $this->generateGUID();
        try {
            $this->db->createPlayerInSeason($id, $playerId, $seasonId, $divisionId, $position, $price);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                http_response_code(409);
                return ['status' => false, 'message' => 'Spieler hat bereits einen Eintrag für diese Saison in dieser Division'];
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

        $body       = $this->body();
        $rows       = $body['rows']        ?? null;
        $divisionId = $body['division_id'] ?? null;
        if (!is_array($rows) || empty($rows)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'rows[] erforderlich'];
        }
        if (!$divisionId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'division_id erforderlich'];
        }

        try {
            $result = $this->db->importCsvRows($rows, $divisionId);
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
        // price fehlt = unverändert, price null/leer = zurück auf Mondpreis, sonst der neue Marktwert
        $priceKey = array_key_exists('price', $body);
        $price    = $priceKey ? $this->db->normalizeMarketValue($body['price']) : false;

        if ($position === null && !$priceKey) {
            http_response_code(400);
            return ['status' => false, 'message' => 'position oder price erforderlich'];
        }

        $validPositions = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];
        if ($position !== null && !in_array($position, $validPositions, true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Invalid position'];
        }
        if ($priceKey && $price === null && $body['price'] !== null && $body['price'] !== '') {
            http_response_code(400);
            return ['status' => false, 'message' => 'price muss zwischen 0 und 50.000.000 liegen'];
        }

        if (!$this->db->updatePlayerInSeason($this->id, $position, $price)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'player_in_season nicht gefunden'];
        }

        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
