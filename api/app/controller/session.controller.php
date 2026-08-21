<?php

class SessionController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'admin'];

    private const ALLOWED_RANGES = ['day', 'month', 'year'];

    protected function get(): mixed
    {
        $range = $this->params['range'] ?? 'day';
        if (!in_array($range, self::ALLOWED_RANGES, true)) {
            $range = 'day';
        }
        return $this->db->getSessionHeatmap($range);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
