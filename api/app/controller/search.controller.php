<?php

class SearchController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

    protected function get(): mixed
    {
        $q = trim($this->params['q'] ?? '');
        if (mb_strlen($q) < 3) {
            return ['players' => [], 'clubs' => [], 'managers' => [], 'teams' => []];
        }
        return $this->db->search($q);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
