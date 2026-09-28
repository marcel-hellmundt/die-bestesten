<?php

/**
 * Länderpunkte auf der Karte (/karte): GET eigene besuchte Länder, POST Land eintragen, DELETE austragen.
 */
class ManagerCountryController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'DELETE' => 'manager'];

    protected function get(): mixed
    {
        return $this->db->getVisitedCountryIds($GLOBALS['auth_manager_id']);
    }

    protected function post(): mixed
    {
        $countryId = $this->body()['country_id'] ?? null;
        if (!is_string($countryId) || !preg_match('/^[A-Za-z]{2}$/', $countryId)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'country_id (ISO Alpha-2) is required'];
        }

        try {
            if (!$this->db->markCountryVisited($GLOBALS['auth_manager_id'], $countryId)) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Land nicht gefunden'];
            }
        } catch (PDOException $e) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Länderpunkte sind noch nicht eingerichtet (Migration manager_country fehlt)'];
        }

        http_response_code(201);
        return ['status' => true];
    }

    protected function patch(): mixed { return $this->methodNotAllowed(); }

    protected function delete(): mixed
    {
        if (!$this->id) {
            http_response_code(400);
            return ['status' => false, 'message' => 'country id required'];
        }

        $this->db->unmarkCountryVisited($GLOBALS['auth_manager_id'], $this->id);
        return ['status' => true];
    }
}
