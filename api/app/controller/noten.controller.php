<?php

class NotenController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'guest'];

    protected function get(): mixed
    {
        $matchdayId = $this->params['matchday_id'] ?? null;
        return $this->db->getNotenOverview($matchdayId);
    }

    // POST /noten/track — anonymes Aufruf-Tracking der Gast-Seite, siehe noten_guest_visit.
    // anon_id optional (nur nach Consent-Banner-Zustimmung vom Client gesetzt); leichtes
    // UUID-Format-Check statt strengem Validieren, ein falsches Format landet einfach als eigene
    // (Format-fremde) ID in der Tabelle, was hier keinen Schaden anrichtet.
    protected function post(): mixed
    {
        if ($this->id !== 'track') return $this->methodNotAllowed();

        $body = $this->body();
        $anonId = $body['anon_id'] ?? null;
        if ($anonId !== null && !preg_match('/^[0-9a-f-]{36}$/i', $anonId)) {
            $anonId = null;
        }
        $this->db->trackNotenGuestVisit($anonId);
        return ['status' => true];
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
