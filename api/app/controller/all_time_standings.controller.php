<?php

class AllTimeStandingsController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

    protected function get(): mixed
    {
        if ($this->id === 'by_season') {
            return $this->db->getAllTimeStandingsBySeason();
        }

        return $this->db->getAllTimeStandings();
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
