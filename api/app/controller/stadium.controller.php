<?php

class StadiumController extends _BaseController
{
    public static array $methodRoles = ['POST' => 'admin'];

    protected function get(): mixed { return $this->methodNotAllowed(); }

    protected function post(): mixed
    {
        $body         = $this->body();
        $clubId       = $body['club_id']       ?? null;
        $officialName = $body['official_name'] ?? null;

        if (!$clubId || !$officialName) {
            http_response_code(400);
            return ['status' => false, 'message' => 'club_id and official_name are required'];
        }

        $name       = $body['name']        ?? null;
        $capacity   = isset($body['capacity']) && $body['capacity'] !== '' ? (int) $body['capacity'] : null;
        $lat        = isset($body['lat'])      && $body['lat']      !== '' ? (float) $body['lat']      : null;
        $lng        = isset($body['lng'])      && $body['lng']      !== '' ? (float) $body['lng']      : null;
        $openedDate = $body['opened_date'] ?: null;
        $fromDate   = $body['from_date']   ?: date('Y-m-d');

        $stadiumId = $this->generateGUID();
        $this->db->createStadium($stadiumId, $officialName, $name, $capacity, $lat, $lng, $openedDate);

        $linkId = $this->generateGUID();
        $this->db->linkClubStadium($linkId, $clubId, $stadiumId, $fromDate);

        http_response_code(201);
        return ['status' => true, 'id' => $stadiumId];
    }

    protected function patch(): mixed { return $this->methodNotAllowed(); }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
