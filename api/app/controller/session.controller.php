<?php

class SessionController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'admin'];

    protected function get(): mixed
    {
        $days = isset($this->params['days']) ? max(1, min(31, (int) $this->params['days'])) : 7;
        return $this->db->getSessionHeatmap($days);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
