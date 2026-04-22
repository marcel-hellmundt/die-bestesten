<?php

class BuyController extends _BaseController
{
    public static array $methodRoles = ['POST' => 'manager'];

    protected function post(): mixed
    {
        $body     = $this->body();
        $teamId   = $body['team_id']           ?? null;
        $playerId = $body['player_id']         ?? null;
        $windowId = $body['transferwindow_id'] ?? null;

        if (!$teamId || !$playerId || !$windowId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id, player_id, transferwindow_id required'];
        }
        if ($this->db->getTeamOwner($teamId) !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }
        if (!$this->db->isTransferwindowOpen($windowId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Transferwindow not open'];
        }
        if ($this->db->isPlayerAlreadyInAnyTeam($playerId)) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Player already in a team'];
        }
        if ($this->db->isPositionFull($teamId, $playerId)) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Position limit reached'];
        }

        $result = $this->db->buyPlayer($teamId, $playerId, $windowId);
        return ['status' => true, 'price' => $result['price']];
    }

    protected function get(): mixed    { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
