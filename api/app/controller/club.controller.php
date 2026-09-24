<?php

class ClubController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'manager', 'PATCH' => 'maintainer']; // POST further restricted per action inside

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

        if ($this->db->clubNameExists($name)) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Ein Club mit diesem Namen existiert bereits'];
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

    // PATCH /club/:id — {primary_color?, secondary_color?} Vereinsfarben (Hex #rrggbb oder null zum Löschen)
    protected function patch(): mixed
    {
        if (!$this->id || $this->sub) return $this->methodNotAllowed();

        if (!$this->db->getClubById($this->id)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Club not found'];
        }

        $body = $this->body();
        $fields = [];
        foreach (['primary_color', 'secondary_color'] as $key) {
            if (!array_key_exists($key, $body)) continue;
            $value = $body[$key];
            if ($value === null || $value === '') {
                $fields[$key] = null;
            } elseif (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $fields[$key] = strtolower($value);
            } else {
                http_response_code(422);
                return ['status' => false, 'message' => "$key muss ein Hex-Farbwert (#rrggbb) oder null sein"];
            }
        }

        if (!$fields) {
            http_response_code(400);
            return ['status' => false, 'message' => 'primary_color und/oder secondary_color erforderlich'];
        }

        $this->db->updateClubColors($this->id, $fields);
        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
