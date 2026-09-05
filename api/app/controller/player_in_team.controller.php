<?php

class PlayerInTeamController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

    protected function get(): mixed
    {
        $playerId = $this->params['player_id'] ?? null;
        if ($playerId) {
            $seasonId = $this->params['season_id'] ?? null;
            if ($seasonId) {
                return $this->db->getTeamHistoryByPlayerId($playerId, $seasonId);
            }
            return $this->db->getTeamByPlayerId($playerId);
        }

        $teamId = $this->params['team_id'] ?? null;
        if (!$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id or player_id required'];
        }
        if (!empty($this->params['include_former'])) {
            $current      = $this->db->getSquadByTeamId($teamId);
            $formerResult = $this->db->getFormerSquadByTeamId($teamId);

            // Aktuell noch im Kader stehende zugeloste Spieler zusätzlich in drafted_squad
            // aufnehmen (in_squad=true) — vor den bereits am Spieltag 1 wieder verkauften
            // Zulosungen (in_squad=false), die aus getFormerSquadByTeamId() kommen.
            $draftedInSquad = array_values(array_map(function ($p) {
                $p['in_squad'] = true;
                return $p;
            }, array_filter($current, fn($p) => $p['is_drafted'])));

            $draftedSold = array_map(function ($p) {
                $p['in_squad'] = false;
                return $p;
            }, $formerResult['drafted_squad']);

            return [
                'current'       => $current,
                'former'        => $formerResult['former'],
                'drafted_squad' => array_merge($draftedInSquad, $draftedSold),
            ];
        }
        return $this->db->getSquadByTeamId($teamId);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
