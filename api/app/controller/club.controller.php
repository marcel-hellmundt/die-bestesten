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

        if ($this->id === 'migrate') {
            if (!$this->isAdmin()) {
                http_response_code(403);
                return ['status' => false, 'message' => 'Forbidden'];
            }
            return $this->db->migrateClub();
        }

        return $this->methodNotAllowed();
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

        $result = ImageUpload::store($_FILES['image'] ?? [], "club/{$this->id}.png", 'image/png');
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
