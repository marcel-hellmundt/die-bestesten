<?php

class PlayerInClubController extends _BaseController
{
    public static array $methodRoles = ['POST' => 'maintainer', 'PATCH' => 'maintainer'];

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

    protected function patch(): mixed
    {
        $body   = $this->body();
        $toDate = $body['to_date'] ?? null;
        if (!$toDate) {
            http_response_code(400);
            return ['message' => 'to_date fehlt'];
        }

        if (!$this->db->endPlayerInClub($this->id, $toDate)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'player_in_club nicht gefunden oder bereits beendet'];
        }

        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
