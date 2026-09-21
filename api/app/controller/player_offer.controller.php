<?php

class PlayerOfferController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'PATCH' => 'manager', 'DELETE' => 'manager'];

    private function fail(array $result): array
    {
        http_response_code($result['http'] ?? 400);
        return ['status' => false, 'message' => $result['message'] ?? 'Error'];
    }

    protected function get(): mixed
    {
        $teamId = $this->params['team_id'] ?? null;
        if (!$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id required'];
        }
        if (!$this->ownsTeam($teamId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        if ($this->id === 'squad') {
            return $this->db->getOfferableSquad($teamId);
        }

        if ($this->id === 'eligibility') {
            return $this->db->getPlayerOfferEligibility($teamId);
        }

        if ($this->id === 'quote') {
            $playerId = $this->params['player_id'] ?? null;
            if (!$playerId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'player_id required'];
            }
            return $this->db->getPlayerOfferQuote($teamId, $playerId);
        }

        $direction = ($this->params['direction'] ?? 'incoming') === 'outgoing' ? 'outgoing' : 'incoming';
        return $this->db->getPlayerOffers($teamId, $direction);
    }

    protected function post(): mixed
    {
        $body     = $this->body();
        $teamId   = $body['team_id']   ?? null;
        $playerId = $body['player_id'] ?? null;
        $value    = isset($body['offer_value']) ? (int) $body['offer_value'] : null;
        $offered  = isset($body['offered_player_ids']) && is_array($body['offered_player_ids']) ? $body['offered_player_ids'] : [];

        if (!$teamId || !$playerId || $value === null) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id, player_id, offer_value required'];
        }
        if (!$this->ownsTeam($teamId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        $result = $this->db->createPlayerOffer($teamId, $playerId, $value, $offered);
        if (!empty($result['error'])) return $this->fail($result);
        return ['status' => true, 'offer_id' => $result['id']];
    }

    // Empfänger antwortet: {team_id, action: accept|decline|counter, offer_value? (nur bei counter)}. Empfänger ist
    // beim normalen Angebot der Verkäufer, beim Gegenangebot der Bieter. Der Betrag eines Angebots ist
    // unveränderlich (kein PATCH auf offer_value) — man nimmt immer genau das an, was man sieht; ein anderer
    // Betrag geht nur über ein Gegenangebot (nur der Verkäufer, nur auf ein normales Angebot).
    protected function patch(): mixed
    {
        $body   = $this->body();
        $teamId = $body['team_id'] ?? null;
        $action = $body['action']  ?? null;

        if (!$this->id || !$teamId || !in_array($action, ['accept', 'decline', 'counter'], true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'offer id, team_id and action (accept|decline|counter) required'];
        }
        if (!$this->ownsTeam($teamId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        if ($action === 'counter') {
            $value = isset($body['offer_value']) ? (int) $body['offer_value'] : 0;
            if ($value <= 0) {
                http_response_code(400);
                return ['status' => false, 'message' => 'offer_value required for counter'];
            }
            $result = $this->db->counterPlayerOffer($this->id, $teamId, $value);
        } elseif ($action === 'accept') {
            $result = $this->db->acceptPlayerOffer($this->id, $teamId);
        } else {
            $result = $this->db->declinePlayerOffer($this->id, $teamId);
        }
        if (!empty($result['error'])) return $this->fail($result);
        return $result;
    }

    // Initiator storniert sein eigenes offenes Angebot (jederzeit): Bieter sein Angebot, Verkäufer sein Gegenangebot.
    protected function delete(): mixed
    {
        $body   = $this->body();
        $teamId = $body['team_id'] ?? null;

        if (!$this->id || !$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'offer id and team_id required'];
        }
        if (!$this->ownsTeam($teamId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }
        if (!$this->db->cancelPlayerOffer($this->id, $teamId)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Offer not found or already resolved'];
        }
        return ['status' => true];
    }
}
