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
            // aufnehmen (in_squad=true) neben den bereits am Spieltag 1 wieder verkauften
            // Zulosungen (in_squad=false) aus getFormerSquadByTeamId() — beide Gruppen kommen
            // bereits einzeln nach Position/Punkte/Marktwert sortiert aus fetchPlayerDetails(),
            // nach dem Zusammenführen daher gleich wieder neu sortieren (nicht nach
            // in_squad-Status gruppiert stehen lassen).
            $draftedInSquad = array_values(array_map(function ($p) {
                $p['in_squad'] = true;
                return $p;
            }, array_filter($current, fn($p) => $p['is_drafted'])));

            $draftedSold = array_map(function ($p) {
                $p['in_squad'] = false;
                return $p;
            }, $formerResult['drafted_squad']);

            $positionOrder = ['GOALKEEPER' => 0, 'DEFENDER' => 1, 'MIDFIELDER' => 2, 'FORWARD' => 3];
            $draftedSquad  = array_merge($draftedInSquad, $draftedSold);
            usort($draftedSquad, fn($a, $b) =>
                ($positionOrder[$a['position']] ?? 9) <=> ($positionOrder[$b['position']] ?? 9)
                ?: $b['points'] <=> $a['points']
                ?: $b['price'] <=> $a['price']
            );

            return [
                'current'       => $current,
                'former'        => $formerResult['former'],
                'drafted_squad' => $draftedSquad,
            ];
        }
        return $this->db->getSquadByTeamId($teamId);
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
