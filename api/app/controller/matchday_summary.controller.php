<?php

/**
 * Spieltags-Zusammenfassung (siehe MatchdaySummaryTrait):
 *   GET   /matchday_summary          — noch nicht gesehene Zusammenfassungen der letzten Tage (Auth)
 *   GET   /matchday_summary/:id      — eine eigene Zusammenfassung, zum Wieder-Öffnen aus der Benachrichtigung (Auth)
 *   GET   /matchday_summary/preview  — Vorschau für einen Manager und Spieltag, schreibt nichts (Admin)
 *   PATCH /matchday_summary/seen     — eigene Zusammenfassungen als gesehen markieren (Auth)
 */
class MatchdaySummaryController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'PATCH' => 'manager', 'DELETE' => 'manager'];

    protected function get(): mixed
    {
        $managerId = $GLOBALS['auth_manager_id'];
        if ($this->id === null) {
            return $this->db->getPendingMatchdaySummaries($managerId);
        }
        if ($this->sub !== null) return $this->methodNotAllowed();

        if ($this->id === 'preview') {
            if (!$this->isAdmin()) {
                http_response_code(403);
                return ['status' => false, 'message' => 'Keine Berechtigung'];
            }
            $previewManagerId = $this->params['manager_id'] ?? null;
            $matchdayId       = $this->params['matchday_id'] ?? null;
            if (!is_string($previewManagerId) || !is_string($matchdayId) || $previewManagerId === '' || $matchdayId === '') {
                http_response_code(400);
                return ['status' => false, 'message' => 'manager_id und matchday_id erforderlich'];
            }
            $summary = $this->db->previewMatchdaySummary($previewManagerId, $matchdayId);
            if ($summary === null) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Für diesen Manager gibt es an diesem Spieltag nichts zu zeigen'];
            }
            return $summary;
        }

        $summary = $this->db->getMatchdaySummary($this->id, $managerId);
        if ($summary === null) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Zusammenfassung nicht gefunden'];
        }
        return $summary;
    }

    protected function patch(): mixed
    {
        if ($this->id !== 'seen' || $this->sub !== null) return $this->methodNotAllowed();

        $ids = $this->body()['ids'] ?? null;
        if (!is_array($ids)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'ids erforderlich'];
        }
        return ['status' => true, 'updated' => $this->db->markMatchdaySummariesSeen($GLOBALS['auth_manager_id'], $ids)];
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
