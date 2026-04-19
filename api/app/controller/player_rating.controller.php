<?php

class PlayerRatingController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'maintainer', 'PATCH' => 'maintainer'];

    protected function get(): mixed
    {
        $matchdayId = $this->params['matchday_id'] ?? null;
        $clubId     = $this->params['club_id']     ?? null;

        if ($this->id === 'status') {
            if (!$matchdayId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'matchday_id ist erforderlich'];
            }
            return $this->db->getClubStatusByMatchday($matchdayId);
        }

        if (!$matchdayId || !$clubId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'matchday_id und club_id sind erforderlich'];
        }

        return $this->db->getPlayerRatingsByMatchdayAndClub($matchdayId, $clubId);
    }

    protected function post(): mixed
    {
        if ($this->id !== 'init') return $this->methodNotAllowed();

        $body       = $this->body();
        $matchdayId = $body['matchday_id'] ?? null;
        $clubId     = $body['club_id']     ?? null;

        if (!$matchdayId || !$clubId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'matchday_id und club_id sind erforderlich'];
        }

        $matchday = $this->db->getMatchdayById($matchdayId);
        if (!$matchday) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Spieltag nicht gefunden'];
        }

        if ($matchday['completed']) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Spieltag ist bereits abgeschlossen'];
        }

        $isAdmin = in_array('admin', $GLOBALS['auth_roles'] ?? []);
        if (!$isAdmin && (!$matchday['kickoff_date'] || new \DateTime() < new \DateTime($matchday['kickoff_date']))) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Spieltag hat noch nicht begonnen'];
        }

        return $this->db->initPlayerRatingsForClub($matchdayId, $clubId);
    }

    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        $matchdayId = $this->db->getMatchdayIdForRating($this->id);
        if (!$matchdayId) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Rating nicht gefunden'];
        }

        $matchday = $this->db->getMatchdayById($matchdayId);
        if ($matchday && $matchday['completed']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Spieltag ist abgeschlossen — Ratings gesperrt'];
        }

        $body = $this->body();
        $updated = $this->db->updatePlayerRating($this->id, $body);

        if (!$updated) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Rating nicht gefunden oder keine Änderung'];
        }

        $row = $this->db->getPlayerRatingById($this->id);
        return ['status' => true, 'rating' => $row ?: null];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
