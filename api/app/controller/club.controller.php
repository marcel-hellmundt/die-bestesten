<?php

class ClubController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'manager']; // further restricted per action inside

    protected function get(): mixed
    {
        if ($this->id) {
            $club = $this->db->getClubById($this->id);
            if (!$club) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Club not found'];
            }
            return $club;
        }

        return $this->db->getClubList($this->params['country_id'] ?? null);
    }

    protected function post(): mixed
    {
        if ($this->id && $this->sub === 'logo') {
            return $this->uploadLogo();
        }

        if (!$this->id) {
            return $this->create();
        }

        return $this->methodNotAllowed();
    }

    private function create(): mixed
    {
        if (!$this->isAdmin()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Forbidden'];
        }

        $body      = $this->body();
        $countryId = $body['country_id'] ?? null;
        $name      = trim($body['name'] ?? '');

        if (!$countryId || !$name) {
            http_response_code(400);
            return ['status' => false, 'message' => 'country_id and name are required'];
        }

        $shortName = isset($body['short_name']) ? trim($body['short_name']) : null;
        $shortName = $shortName !== '' ? $shortName : null;

        $id = $this->generateGUID();
        $this->db->createClub($id, $countryId, $name, $shortName);

        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }

    private function uploadLogo(): mixed
    {
        if (!$this->isMaintainer()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Forbidden'];
        }

        if (!$this->db->getClubById($this->id)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Club not found'];
        }

        $result = ImageUpload::store($_FILES['image'] ?? [], "club/{$this->id}.png", 'png');
        if (!$result['status']) {
            http_response_code($result['code']);
            return $result;
        }

        $this->db->setClubLogoUploaded($this->id);
        return ['status' => true];
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
