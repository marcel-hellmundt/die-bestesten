<?php

class ClubInSeasonController extends _BaseController
{
    public static array $publicMethods = ['GET'];

    protected function get(): mixed
    {
        $clubId = $this->params['club_id'] ?? null;
        if (!$clubId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'club_id query parameter required'];
        }
        return $this->db->getClubInSeasonByClub($clubId);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
