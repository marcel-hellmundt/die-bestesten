<?php

class PlayerRatingController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'maintainer', 'PATCH' => 'maintainer'];

    protected function get(): mixed
    {
        $matchdayId = $this->params['matchday_id'] ?? null;
        $clubId     = $this->params['club_id']     ?? null;

        if ($this->id === 'status') {
            if (!$matchdayId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'matchday_id ist erforderlich'];
            }
            return $this->db->getClubStatusByMatchday($matchdayId);
        }

        if (!$matchdayId || !$clubId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'matchday_id und club_id sind erforderlich'];
        }

        return $this->db->getPlayerRatingsByMatchdayAndClub($matchdayId, $clubId);
    }

    protected function post(): mixed
    {
        if ($this->id === 'validate-csv') {
            $matchdayId = $_POST['matchday_id'] ?? null;
            if (!$matchdayId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'matchday_id ist erforderlich'];
            }

            $file = $_FILES['csv']['tmp_name'] ?? null;
            if (!$file || !is_readable($file)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'CSV-Datei fehlt'];
            }

            // Build DB map: kicker_id (int) → {adjusted_points, displayname}
            // CSV scoring differs: starting=4 (ours=2, +2), substitute=2 (ours=1, +1), assist=2 (ours=1, +1 each)
            $dbRows = $this->db->getPlayerRatingsForMatchday($matchdayId);
            $dbMap  = [];
            foreach ($dbRows as $row) {
                if ($row['kicker_id'] === null) continue;
                $participationBonus = match($row['participation']) {
                    'starting'   => 2,
                    'substitute' => 1,
                    default      => 0,
                };
                $adjusted = (int) $row['points'] + $participationBonus + (int) $row['assists'];
                $dbMap[(int) $row['kicker_id']] = [
                    'points'      => $adjusted,
                    'displayname' => $row['displayname'],
                ];
            }

            // Parse CSV: skip header, col 0 = id (pl-kXXXX → kicker_id), col 4 = displayname, col 8 = points
            $handle     = fopen($file, 'r');
            $mismatches = [];
            $checked    = 0;
            $firstLine  = true;
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($firstLine) { $firstLine = false; continue; }
                if (trim($line) === '') continue;
                $cols        = str_getcsv($line, ';');
                $kickerId    = isset($cols[0]) ? (int) substr($cols[0], 4) : null;
                $displayname = $cols[4] ?? null;
                $csvPoints   = isset($cols[8]) ? (int) $cols[8] : null;
                if ($kickerId === null || $displayname === null || $csvPoints === null) continue;
                if (!array_key_exists($kickerId, $dbMap)) {
                    if ($csvPoints > 0) {
                        $mismatches[] = [
                            'kicker_id'   => $kickerId,
                            'displayname' => $displayname,
                            'csv_points'  => $csvPoints,
                            'db_points'   => null,
                            'error'       => 'player not found in db',
                        ];
                    }
                    continue;
                }
                $checked++;
                if ($dbMap[$kickerId]['points'] !== $csvPoints) {
                    $mismatches[] = [
                        'kicker_id'   => $kickerId,
                        'displayname' => $displayname,
                        'csv_points'  => $csvPoints,
                        'db_points'   => $dbMap[$kickerId]['points'],
                        'error'       => 'points mismatch',
                    ];
                }
            }
            fclose($handle);

            if (empty($mismatches)) {
                return ['ok' => true, 'checked' => $checked];
            }
            return ['ok' => false, 'mismatches' => $mismatches];
        }

        if ($this->id !== 'init') return $this->methodNotAllowed();

        $body       = $this->body();
        $matchdayId = $body['matchday_id'] ?? null;
        $clubId     = $body['club_id']     ?? null;

        if (!$matchdayId || !$clubId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'matchday_id und club_id sind erforderlich'];
        }

        $matchday = $this->db->getMatchdayById($matchdayId);
        if (!$matchday) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Spieltag nicht gefunden'];
        }

        if ($matchday['completed']) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Spieltag ist bereits abgeschlossen'];
        }

        $isAdmin = in_array('admin', $GLOBALS['auth_roles'] ?? []);
        if (!$isAdmin && (!$matchday['kickoff_date'] || new \DateTime() < new \DateTime($matchday['kickoff_date']))) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Spieltag hat noch nicht begonnen'];
        }

        return $this->db->initPlayerRatingsForClub($matchdayId, $clubId);
    }

    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        $matchdayId = $this->db->getMatchdayIdForRating($this->id);
        if (!$matchdayId) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Rating nicht gefunden'];
        }

        $matchday = $this->db->getMatchdayById($matchdayId);
        if ($matchday && $matchday['completed']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Spieltag ist abgeschlossen — Ratings gesperrt'];
        }

        $body = $this->body();
        $updated = $this->db->updatePlayerRating($this->id, $body);

        if (!$updated) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Rating nicht gefunden oder keine Änderung'];
        }

        $row = $this->db->getPlayerRatingById($this->id);
        return ['status' => true, 'rating' => $row ?: null];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
