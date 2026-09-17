<?php

class NotenController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest'];

    protected function get(): mixed
    {
        $matchdayId = $this->params['matchday_id'] ?? null;
        return $this->db->getNotenOverview($matchdayId);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
