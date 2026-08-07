<?php

class PlayerInClubController extends _BaseController
{
    public static array $methodRoles = ['POST' => 'maintainer', 'PATCH' => 'maintainer', 'DELETE' => 'maintainer'];

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
        $fields = [];
        if (array_key_exists('club_id', $body))   $fields['club_id']   = $body['club_id'];
        if (array_key_exists('from_date', $body)) $fields['from_date'] = $body['from_date'];
        if (array_key_exists('to_date', $body))   $fields['to_date']   = $body['to_date'];
        if (array_key_exists('on_loan', $body))   $fields['on_loan']   = $body['on_loan'];

        if (empty($fields)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Keine Felder zum Aktualisieren (club_id, from_date, to_date, on_loan)'];
        }

        $current = $this->db->getPlayerInClubById($this->id);
        if (!$current) {
            http_response_code(404);
            return ['status' => false, 'message' => 'player_in_club nicht gefunden'];
        }

        $effectiveFrom = $fields['from_date'] ?? $current['from_date'];
        $effectiveTo   = array_key_exists('to_date', $fields) ? $fields['to_date'] : $current['to_date'];
        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            http_response_code(422);
            return ['status' => false, 'message' => 'Bis-Datum darf nicht vor dem Von-Datum liegen'];
        }

        $this->db->updatePlayerInClub($this->id, $fields);
        return ['status' => true];
    }

    protected function delete(): mixed
    {
        if (!$this->db->deletePlayerInClub($this->id)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'player_in_club nicht gefunden'];
        }

        return ['status' => true];
    }
}
