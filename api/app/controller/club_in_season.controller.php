<?php

class ClubInSeasonController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin', 'PATCH' => 'admin'];

    protected function get(): mixed
    {
        $clubId   = $this->params['club_id']   ?? null;
        $seasonId = $this->params['season_id'] ?? null;

        if ($clubId) {
            return $this->db->getClubInSeasonByClub($clubId);
        }
        if ($seasonId) {
            return $this->db->getClubInSeasonBySeason($seasonId);
        }

        http_response_code(400);
        return ['status' => false, 'message' => 'club_id or season_id query parameter required'];
    }

    protected function post(): mixed
    {
        $body       = $this->body();
        $clubId     = $body['club_id']     ?? null;
        $seasonId   = $body['season_id']   ?? null;
        $divisionId = $body['division_id'] ?? null;
        $position   = array_key_exists('position', $body)
            ? ($body['position'] === null ? null : (int) $body['position'])
            : null;

        if (!$clubId || !$seasonId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'club_id and season_id are required'];
        }

        if ($this->db->clubInSeasonExists($clubId, $seasonId)) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Dieser Verein hat bereits einen Eintrag für diese Saison'];
        }

        $id = $this->generateGUID();
        $this->db->createClubInSeason($id, $clubId, $seasonId, $divisionId ?: null, $position);
        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }

    protected function patch(): mixed
    {
        if (!$this->id) {
            http_response_code(400);
            return ['status' => false, 'message' => 'ID required'];
        }

        $body = $this->body();
        $divisionId = $body['division_id'] ?? null;
        $position   = array_key_exists('position', $body) ? $body['position'] : false;

        if (!$divisionId && $position === false) {
            http_response_code(400);
            return ['status' => false, 'message' => 'No fields to update'];
        }

        $this->db->updateClubInSeason($this->id, $divisionId, $position === false ? null : $position);
        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
