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

        if (!$teamId || !$playerId || $value === null) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id, player_id, offer_value required'];
        }
        if (!$this->ownsTeam($teamId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        $result = $this->db->createPlayerOffer($teamId, $playerId, $value);
        if (!empty($result['error'])) return $this->fail($result);
        return ['status' => true, 'offer_id' => $result['id']];
    }

    // Verkäufer: {team_id, action: accept|decline}. Der Betrag eines Angebots ist unveränderlich
    // (kein PATCH auf offer_value) — der Verkäufer nimmt also immer genau das an, was er sieht.
    protected function patch(): mixed
    {
        $body   = $this->body();
        $teamId = $body['team_id'] ?? null;
        $action = $body['action']  ?? null;

        if (!$this->id || !$teamId || !in_array($action, ['accept', 'decline'], true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'offer id, team_id and action (accept|decline) required'];
        }
        if (!$this->ownsTeam($teamId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Not your team'];
        }

        $result = $action === 'accept'
            ? $this->db->acceptPlayerOffer($this->id, $teamId)
            : $this->db->declinePlayerOffer($this->id, $teamId);
        if (!empty($result['error'])) return $this->fail($result);
        return $result;
    }

    // Bieter storniert sein eigenes offenes Angebot (jederzeit).
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
