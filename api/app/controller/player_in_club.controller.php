<?php

class PlayerInClubController extends _BaseController
{
    public static array $methodRoles = ['POST' => 'maintainer'];

    protected function get(): mixed   { return $this->methodNotAllowed(); }

    protected function post(): mixed
    {
        $body = $this->body();
        foreach (['player_id', 'club_id', 'from_date'] as $f) {
            if (!isset($body[$f])) {
                http_response_code(400);
                return ['message' => "$f fehlt"];
            }
        }
        http_response_code(201);
        return $this->db->createPlayerInClub($body);
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
