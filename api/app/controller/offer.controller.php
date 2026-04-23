<?php

class OfferController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'PATCH' => 'manager', 'DELETE' => 'manager'];

    protected function get(): mixed
    {
        $teamId   = $this->params['team_id']           ?? null;
        $windowId = $this->params['transferwindow_id'] ?? null;

        if ($teamId) {
            if ($this->db->getTeamOwner($teamId) !== $GLOBALS['auth_manager_id']) {
                http_response_code(403);
                return ['status' => false, 'message' => 'Not your team'];
            }
            return $this->db->getMyOffers($teamId);
        }

        if ($windowId) {
            $window = $this->db->getTransferwindowById($windowId);
            if (!$window) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Transferwindow not found'];
            }
            if (strtotime($window['end_date']) >= time()) {
                http_response_code(422);
                return ['status' => false, 'message' => 'Transferphase noch offen'];
            }
            $this->db->settleWindow($windowId);
            return $this->db->getWindowOffers($windowId);
        }

        http_response_code(400);
        return ['status' => false, 'message' => 'team_id or transferwindow_id required'];
    }

    protected function post(): mixed
    {
        $body     = $this->body();
        $teamId   = $body['team_id']           ?? null;
        $playerId = $body['player_id']         ?? null;
        $windowId = $body['transferwindow_id'] ?? null;
        $value    = isset($body['offer_value']) ? (int) $body['offer_value'] : null;

        if (!$teamId || !$playerId || !$windowId || $value === null) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id, player_id, transferwindow_id, offer_value required'];
        }
        if ($this->db->getTeamOwner($teamId) !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        $result = $this->db->submitOffer($teamId, $playerId, $windowId, $value);
        return ['status' => true, 'offer_id' => $result['offer_id']];
    }

    protected function delete(): mixed
    {
        $offerId = $this->urlSegments[1] ?? null;
        $body    = $this->body();
        $teamId  = $body['team_id'] ?? null;

        if (!$offerId || !$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'offer id and team_id required'];
        }
        if ($this->db->getTeamOwner($teamId) !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }
        if (!$this->db->cancelOffer($offerId, $teamId)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Offer not found or already resolved'];
        }
        return ['status' => true];
    }

    protected function patch(): mixed
    {
        $offerId  = $this->urlSegments[1] ?? null;
        $body     = $this->body();
        $teamId   = $body['team_id']    ?? null;
        $newValue = isset($body['offer_value']) ? (int) $body['offer_value'] : null;

        if (!$offerId || !$teamId || $newValue === null) {
            http_response_code(400);
            return ['status' => false, 'message' => 'offer id, team_id and offer_value required'];
        }
        if ($this->db->getTeamOwner($teamId) !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        $result = $this->db->updateOfferValue($offerId, $teamId, $newValue);
        if (isset($result['error'])) {
            $code = $result['error'] === 'not_found' ? 404 : 422;
            http_response_code($code);
            return ['status' => false, 'message' => $result['error']];
        }
        return ['status' => true];
    }
}
