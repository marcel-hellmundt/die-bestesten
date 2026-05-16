<?php

class PlayerInSeasonController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'maintainer'];

    protected function get(): mixed
    {
        if ($this->id === 'bundesliga_count') {
            $seasonId = $this->params['season_id'] ?? null;
            return ['count' => $this->db->getBundesligaPlayerCount($seasonId)];
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
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
