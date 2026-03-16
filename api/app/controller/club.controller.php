<?php

class ClubController extends _BaseController
{
    public static array $publicMethods = ['GET'];

    protected function get(): mixed
    {
        if ($this->id) {
            $club = $this->db->getClubById($this->id);
            if (!$club) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Club not found'];
            }
            return $club;
        }

        return $this->db->getClubList($this->params['country_id'] ?? null);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
