<?php

class SessionController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'admin'];

    private const ALLOWED_RANGES = ['day', 'week', 'month', 'year'];

    protected function get(): mixed
    {
        $range = $this->params['range'] ?? 'week';
        if (!in_array($range, self::ALLOWED_RANGES, true)) {
            $range = 'week';
        }
        return $this->db->getSessionHeatmap($range);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
