<?php

class DivisionController extends _BaseController
{
    public static array $publicMethods = ['GET'];

    protected function get(): mixed
    {
        if ($this->id) {
            $division = $this->db->getDivisionById($this->id);
            if (!$division) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Division not found'];
            }
            return $division;
        }

        return $this->db->getDivisionList();
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
