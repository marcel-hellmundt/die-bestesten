<?php

class PlayerController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'maintainer'];

    protected function get(): mixed
    {
        if ($this->id) {
            $player = $this->db->getPlayerDetail($this->id, $this->params['season_id'] ?? null);
            if (!$player) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Player not found'];
            }
            return $player;
        }

        if (isset($this->params['club_id'])) {
            return $this->db->getPlayersInClub($this->params['club_id'], $this->params['season_id'] ?? null);
        }

        return $this->db->getPlayerList($this->params['country_id'] ?? null, $this->params['season_id'] ?? null);
    }

    protected function post(): mixed
    {
        if ($this->id && $this->sub === 'photo') {
            return $this->uploadPhoto();
        }

        if ($this->id === 'migrate') {
            if (!$this->isAdmin()) {
                http_response_code(403);
                return ['status' => false, 'message' => 'Forbidden'];
            }
            return $this->db->migratePlayer();
        }

        if ($this->id === 'create') {
            $body = $this->body();
            foreach (['kicker_id', 'first_name', 'last_name', 'displayname', 'season_id', 'position', 'price'] as $f) {
                if (!isset($body[$f])) {
                    http_response_code(400);
                    return ['message' => "$f fehlt"];
                }
            }
            return $this->db->createPlayer($body);
        }

        return $this->methodNotAllowed();
    }

    private function uploadPhoto(): mixed
    {
        $seasonId = $_POST['season_id'] ?? null;
        if (!$seasonId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id fehlt'];
        }

        $result = ImageUpload::store($_FILES['image'] ?? [], "player/{$seasonId}/{$this->id}.png", 'png');
        if (!$result['status']) {
            http_response_code($result['code']);
            return $result;
        }

        if (!$this->db->setPlayerPhotoUploaded($this->id, $seasonId)) {
            http_response_code(404);
            return ['status' => false, 'message' => 'player_in_season nicht gefunden'];
        }

        return ['status' => true];
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
