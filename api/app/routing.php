<?php

class Route
{
    public function __construct(
        private string $name,
        private string $class,
        public readonly array $docs = []
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
    public function getClass(): string
    {
        return $this->class;
    }
}

class Routing
{
    private array $routes;

    public function __construct()
    {
        $this->routes = [
            new Route('auth', 'Auth', [
                'title' => 'Auth',
                'description' => 'Authentifizierung — gibt einen JWT zurück (7 Tage gültig)',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/auth',
                        'description' => 'Login mit manager_name und password',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/auth/password-reset-request',
                        'description' => 'Passwort-Reset anfordern — Body: { email } — sendet Mail mit Reset-Link (1h gültig); gibt immer status:true zurück (kein E-Mail-Leak)',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/auth/password-reset',
                        'description' => 'Passwort zurücksetzen — Body: { token, new_password } — Token aus Reset- oder Einladungslink; gibt {status,token,leagues,league_id} zurück (automatischer Login mit neuem JWT); 400 wenn ungültig/abgelaufen',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/auth/switch-league',
                        'description' => 'Liga wechseln — Body: { league_id }; gibt neues JWT mit geänderter league_id zurück; 403 wenn kein Zugang zur angeforderten Liga — Auth',
                        'body' => ['league_id' => 'UUID der Ziel-Liga'],
                    ],
                ],
            ]),

            new Route('league', 'League', [
                'title' => 'League',
                'description' => 'Fantasy-Ligen — jede Liga hat eine eigene Datenbank',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/league',
                        'description' => 'Alle Ligen, alphabetisch sortiert — enthält manager_count (global) und team_count (Teams der aktiven Saison aus der jeweiligen Liga-DB; 0 ohne aktive Saison)',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/league/mine',
                        'description' => 'Aktuelle Liga {id,slug,name,db_name,division_id,fine_ruleset} — bei vorhandenem JWT die Liga aus auth_league_id, sonst Fallback auf die per DB_NAME_LEAGUE konfigurierte Deployment-Liga; 404 wenn nicht gefunden',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/league/:id',
                        'description' => 'Eine Liga per ID — teams[] enthält je Team zusätzlich squad_count und squad_value (aktiver Kader + Marktwertsumme)',
                        'path_params' => [':id' => 'UUID der Liga'],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/league/:id',
                        'description' => 'Spielerpool-Division setzen ({division_id: UUID|null}) oder Sichtbarkeit setzen ({visibility: "public"|"private"}) oder Strafen-Regelsatz setzen ({fine_ruleset: "classic"|"none"}) oder Powerranking an/aus schalten ({powerranking_enabled: bool}) — Admin',
                        'path_params' => [':id' => 'UUID der Liga'],
                        'body' => ['division_id' => 'CHAR(36) UUID oder null (kein Filter)', 'visibility' => '"public" oder "private"', 'fine_ruleset' => '"classic" (Kegelstrafen) oder "none" (keine Strafen)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/:id/join',
                        'description' => 'Liga-Beitrittsanfrage stellen (status=requested); benachrichtigt alle Admins; 403 wenn Liga visibility=private — Auth',
                        'path_params' => [':id' => 'UUID der Liga'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/:id/accept',
                        'description' => 'Einladung annehmen (status invited→active); benachrichtigt alle Admins per E-Mail — Auth; 409 wenn keine ausstehende Einladung',
                        'path_params' => [':id' => 'UUID der Liga'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/:id/decline',
                        'description' => 'Einladung ablehnen (status invited→denied) — Auth; 409 wenn keine ausstehende Einladung',
                        'path_params' => [':id' => 'UUID der Liga'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/:id/invite',
                        'description' => 'Manager einladen (status=invited); benachrichtigt den Manager — Body: {manager_id} — Admin',
                        'path_params' => [':id' => 'UUID der Liga'],
                        'body' => ['manager_id' => 'UUID des Managers'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/:id/approve',
                        'description' => 'Beitrittsanfrage genehmigen (status requested→active); benachrichtigt Manager — Body: {manager_id} — Admin',
                        'path_params' => [':id' => 'UUID der Liga'],
                        'body' => ['manager_id' => 'UUID des Managers'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/:id/deny',
                        'description' => 'Mitgliedschaft ablehnen (status→denied) — Body: {manager_id} — Admin',
                        'path_params' => [':id' => 'UUID der Liga'],
                        'body' => ['manager_id' => 'UUID des Managers'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/league/:id/draft_pool',
                        'description' => 'Spieler der Liga-Division ohne aktives Team in der angegebenen Saison (Pool für die Draft-Zuweisung); Spieler enthalten kicker_id (int, für Abgleich mit externen Draft-Exporten) — ?season_id erforderlich — Admin',
                        'path_params' => [':id' => 'UUID der Liga'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/:id/draft_assign',
                        'description' => 'Weist mehreren Teams auf einmal Spieler zu (player_in_team + transaction, Preis = exakter Marktwert von player_in_season); prüft Positionslimits (GK≤2/DEF≤6/MID≤6/FWD≤4) und Doppelvergabe und überspringt Verstöße mit Grund statt abzubrechen (skipped[{team_id,player_id,reason}]); 422 wenn Spieltag 1 der Division/Saison noch nicht angelegt ist — Body: {season_id, assignments:[{team_id, player_ids:[...]}]} — Admin',
                        'path_params' => [':id' => 'UUID der Liga'],
                        'body' => ['season_id' => 'UUID der Saison', 'assignments' => '[{team_id: UUID, player_ids: [UUID, ...]}]'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/conclude_season',
                        'description' => 'Saisonauszeichnungen vergeben (Meister, Goldene Bürste, Hölzerne Bank); idempotent — Body: { league_id, season_id } — Admin',
                        'body' => ['league_id' => 'UUID', 'season_id' => 'UUID'],
                    ],
                ],
            ]),

            new Route('all_time_standings', 'AllTimeStandings', [
                'title' => 'All-Time Standings',
                'description' => 'Total points per manager across all seasons — Auth',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/all_time_standings',
                        'description' => 'Returns { standings: [{id, manager_name, alias, total_points}], top_matchdays: [{points, matchday_id, matchday_number, team_name, season_id, manager_name}] }',
                    ],
                ],
            ]),

            new Route('session', 'Session', [
                'title' => 'Session',
                'description' => 'Näherungsweise Sitzungsdauer-Erfassung per Heartbeat (jeder authentifizierte Request verlängert/eröffnet eine manager_session) — Admin-Report',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/session',
                        'description' => 'Nutzungsdauer je Manager, gebucketed nach Zeitraum (Heatmap-Rohdaten) — {range, managers[{manager_id,manager_name,alias,buckets:{key:Sekunden},mobile_seconds:{key:Sekunden},desktop_seconds:{key:Sekunden}}]} sortiert nach Gesamtnutzung DESC; buckets-Schlüssel je range: day="YYYY-MM-DDTHH:00:00" (Stunde), month="YYYY-MM-DD" (Tag), year="YYYY-MM-DD" (Montag der Woche); mobile_seconds/desktop_seconds = dieselben Buckets, aber je nur eine Gerätekategorie (device_type mobile/tablet bzw. desktop/unbekannt), unabhängig voneinander dedupliziert; Mobile-Anteil für Färbung = mobile_seconds/(mobile_seconds+desktop_seconds), nicht gegen buckets (kann bei gleichzeitiger Mehrgeräte-Nutzung > buckets-Wert liegen) — Admin',
                        'query_params' => ['range' => '"day" (letzte 24h, stündlich, default) | "month" (letzte 30 Tage, täglich) | "year" (letzte 52 Wochen, wöchentlich)'],
                    ],
                ],
            ]),

            new Route('country', 'Country', [
                'title' => 'Country',
                'description' => 'ISO Alpha-2 Ländercodes',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/country',
                        'description' => 'Alle Länder, alphabetisch sortiert',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/country/:id',
                        'description' => 'Ein Land per ISO Alpha-2 Code',
                        'path_params' => [':id' => 'ISO Alpha-2 Code, z.B. DE'],
                    ],
                ],
            ]),

            new Route('season', 'Season', [
                'title' => 'Season',
                'description' => 'Saisons — die aktive Saison hat das höchste start_date',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/season',
                        'description' => 'Alle Saisons, neueste zuerst',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/season/active',
                        'description' => 'Die aktuell aktive Saison',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/season/:id',
                        'description' => 'Eine Saison per ID',
                        'path_params' => [':id' => 'UUID der Saison'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/season',
                        'description' => 'Neue Saison anlegen — {start_date} → {id}; 500 bei doppeltem start_date (UNIQUE) — Admin',
                        'body' => ['start_date' => 'YYYY-MM-DD (erforderlich)'],
                    ],
                ],
            ]),

            new Route('matchday', 'Matchday', [
                'title' => 'Matchday',
                'description' => 'Spieltage — divisionsspezifisch; jede Liga pflegt eigene Spieltage',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/matchday',
                        'description' => 'Alle Spieltage, optional gefiltert nach Saison; mit season_id: filtert nach Division (division_id-Param oder Division der aktiven Liga als Default), enthält has_ratings (bool) — Auth',
                        'query_params' => [
                            'season_id' => 'UUID der Saison (optional)',
                            'division_id' => 'UUID der Division (optional) — überschreibt die Division der aktiven Liga, z.B. für Admins, die Spieltage anderer Ligen verwalten',
                        ],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/matchday/:id',
                        'description' => 'Ein Spieltag per ID — Auth',
                        'path_params' => [':id' => 'UUID des Spieltags'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/matchday',
                        'description' => 'Neuen Spieltag anlegen — Body: { season_id, number, start_date, kickoff_date, division_id? }; division_id optional, Default = Division der aktiven Liga; 409 bei Duplikat, 422 wenn keine Division konfiguriert oder division_id ungültig — Admin',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/matchday/:id',
                        'description' => 'Entweder completed-Status setzen — Body: { completed: bool }; bei completed=true: team_rating + Transaktionen für alle Teams erstellen, Achievements auswerten, Notifications senden, Zusammenfassungs-E-Mail an alle Admins mit hinterlegter E-Mail-Adresse senden — Admin. Oder Stammdaten bearbeiten — Body: beliebige Kombination aus number, start_date, kickoff_date; 404 wenn nicht gefunden, 409 wenn Spieltag bereits completed oder Nummer bereits vergeben, 422 wenn kickoff_date vor start_date liegt — Admin',
                        'path_params' => [':id' => 'UUID des Spieltags'],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/matchday/:id',
                        'description' => 'Spieltag löschen — 404 wenn nicht gefunden, 409 wenn bereits completed oder bereits in der Liga verwendet (Aufstellungen, Ratings, Transaktionen, H2H) oder noch von Bewertungen/Transferfenstern referenziert — Admin',
                        'path_params' => [':id' => 'UUID des Spieltags'],
                    ],
                ],
            ]),

            new Route('club', 'Club', [
                'title' => 'Club',
                'description' => 'Fußballvereine',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/club',
                        'description' => 'Alle Clubs, optional gefiltert nach Land — enthält aktuelles Stadion als stadium-Objekt (oder null)',
                        'query_params' => ['country_id' => 'ISO Alpha-2 Code (optional)'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/club/:id',
                        'description' => 'Ein Club per ID — enthält aktuelles Stadion als stadium-Objekt (oder null)',
                        'path_params' => [':id' => 'UUID des Clubs'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/club',
                        'description' => 'Neuen Club anlegen — {country_id, name, short_name?} → {id}; 409 bei Namensduplikat — Admin',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/club/:id/logo',
                        'description' => 'Vereinswappen hochladen (multipart/form-data, Feld "image", PNG) — setzt club.logo_uploaded — Maintainer+',
                        'path_params' => [':id' => 'UUID des Clubs'],
                    ],
                ],
            ]),

            new Route('stadium', 'Stadium', [
                'title' => 'Stadium',
                'description' => 'Stadien — werden per club_stadium mit einem Club verknüpft',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/stadium',
                        'description' => 'Alle Stadien inkl. lat/lng, capacity, other_visitors ([{id,manager_name}] anderer Manager, die das Stadion besucht haben, eingeloggter Manager ausgeschlossen) und aktuell verknüpftem Club ({id,name,logo_uploaded} oder null) — Auth',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/stadium',
                        'description' => 'Neues Stadion anlegen und direkt als aktuelles Stadion eines Clubs verknüpfen (club_stadium, to_date NULL) — Admin',
                        'body' => [
                            'club_id' => 'UUID des Clubs (erforderlich)',
                            'official_name' => 'Offizieller Name (erforderlich)',
                            'name' => 'Spitzname (optional)',
                            'capacity' => 'Zuschauerkapazität (optional)',
                            'lat' => 'Breitengrad (optional)',
                            'lng' => 'Längengrad (optional)',
                            'from_date' => 'Seit wann der Club dieses Stadion nutzt YYYY-MM-DD (optional, Default heute)',
                        ],
                    ],
                ],
            ]),

            new Route('manager_stadium', 'ManagerStadium', [
                'title' => 'ManagerStadium',
                'description' => 'Von einem Manager als besucht markierte Stadien',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/manager_stadium',
                        'description' => 'Stadion-IDs, die der eingeloggte Manager als besucht markiert hat — Auth',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/manager_stadium',
                        'description' => 'Stadion als besucht markieren (idempotent) — Auth',
                        'body' => ['stadium_id' => 'UUID des Stadions (erforderlich)'],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/manager_stadium/:stadium_id',
                        'description' => 'Markierung als besucht wieder entfernen (idempotent) — Auth',
                        'path_params' => [':stadium_id' => 'UUID des Stadions'],
                    ],
                ],
            ]),

            new Route('division', 'Division', [
                'title' => 'Division',
                'description' => 'Spielklassen (1. Bundesliga, 2. Bundesliga, …)',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/division',
                        'description' => 'Alle Divisionen, sortiert nach Level',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/division/:id',
                        'description' => 'Eine Division per ID',
                        'path_params' => [':id' => 'UUID der Division'],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '\division:id',
                        'description' => 'Startbudget + Punkte-Bonus setzen — Admin',
                        'path_params' => [':id' => 'UUID der Division'],
                        'body' => ['starting_budget' => 'INT > 0 (Startbudget eines neuen Fantasy-Teams)', 'points_bonus' => 'INT > 0 (Marktwert-/Auszahlungs-Bonus pro Saisonpunkt)'],
                    ],
                ],
            ]),

            new Route('club_in_season', 'ClubInSeason', [
                'title' => 'ClubInSeason',
                'description' => 'Club-Saison-Zuordnungen mit Division und Tabellenplatz',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/club_in_season',
                        'description' => 'Einträge nach Club (neueste zuerst) oder nach Saison (nach Platz sortiert)',
                        'query_params' => [
                            'club_id' => 'UUID des Clubs',
                            'season_id' => 'UUID der Saison',
                        ],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/club_in_season',
                        'description' => 'Neuen Eintrag anlegen — 409 bei doppelter club_id+season_id Kombination',
                        'query_params' => [
                            'club_id' => 'UUID des Clubs (erforderlich)',
                            'season_id' => 'UUID der Saison (erforderlich)',
                            'division_id' => 'UUID der Division (optional)',
                            'position' => 'Tabellenplatz als Integer, null erlaubt (optional)',
                        ],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/club_in_season/:id',
                        'description' => 'Division und/oder Tabellenplatz eines Eintrags aktualisieren',
                        'path_params' => [':id' => 'UUID des Eintrags'],
                        'query_params' => [
                            'division_id' => 'UUID der neuen Division (optional)',
                            'position' => 'Neuer Tabellenplatz, null erlaubt (optional)',
                        ],
                    ],
                ],
            ]),

            new Route('transferwindow', 'Transferwindow', [
                'title' => 'Transferwindow',
                'description' => 'Transferfenster je Spieltag — üblicherweise 2, selten 4 pro Spieltag',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/transferwindow',
                        'description' => 'Alle Transferfenster, optional gefiltert nach Spieltag oder Saison; jedes Fenster enthält offer_count (Anzahl Gebote)',
                        'query_params' => [
                            'matchday_id' => 'UUID des Spieltags (optional)',
                            'season_id' => 'UUID der Saison (optional) — gibt alle TF der Saison zurück',
                            'division_id' => 'UUID der Division (optional, nur mit season_id) — überschreibt die Division der aktiven Liga',
                        ],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/transferwindow/:id',
                        'description' => 'Ein Transferfenster per ID',
                        'path_params' => [':id' => 'UUID des Transferfensters'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/transferwindow',
                        'description' => 'Neues Transferfenster anlegen — Body: { matchday_id, start_date, end_date }; muss innerhalb Spieltag-Start und -Anpfiff liegen, darf sich nicht mit bestehenden Fenstern überschneiden — Maintainer+',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/transferwindow/:id',
                        'description' => 'Start/Ende bearbeiten — Body: beliebige Kombination aus start_date, end_date; gleiche Validierung wie beim Anlegen (422 bei Regelverstoß, 409 bei Überschneidung) — Admin',
                        'path_params' => [':id' => 'UUID des Transferfensters'],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/transferwindow/:id',
                        'description' => 'Transferfenster löschen — 409 wenn bereits Gebote (offer) oder Verkäufe (sell) darauf existieren — Admin',
                        'path_params' => [':id' => 'UUID des Transferfensters'],
                    ],
                ],
            ]),

            new Route('player_rating', 'PlayerRating', [
                'title' => 'PlayerRating',
                'description' => 'Spieler-Bewertungen pro Spieltag erfassen und abrufen',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/player_rating',
                        'description' => 'Alle Ratings eines Clubs an einem Spieltag (mit Spieler-Infos inkl. price, starting_count); sortiert nach starting_count DESC, position, price DESC — Auth',
                        'query_params' => [
                            'matchday_id' => 'UUID des Spieltags (erforderlich)',
                            'club_id' => 'UUID des Clubs (erforderlich)',
                        ],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/player_rating/best_xi',
                        'description' => 'Beste valide 11 für einen Spieltag (Formationen 343/352/433/442/451/532/541, maximale Gesamtpunkte) — gibt {formation, players[{player_id,displayname,position,points,grade,club_id,club_name,club_short_name}], total_points} zurück; free_agents_only=1 schließt Spieler in Fantasy-Teams aus — Auth',
                        'query_params' => [
                            'matchday_id'     => 'UUID des Spieltags (erforderlich)',
                            'free_agents_only' => '1 = nur vereinslose Spieler (optional, default 0)',
                        ],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/player_rating/status',
                        'description' => 'Aggregierter Bewertungsstatus aller Clubs für einen Spieltag — gibt [{club_id, rating_count, starter_count, grade_count}] zurück',
                        'query_params' => [
                            'matchday_id' => 'UUID des Spieltags (erforderlich)',
                        ],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player_rating/init',
                        'description' => 'Erstellt leere Ratings für alle aktuellen Spieler eines Clubs mit gültigem player_in_season (Position + Marktwert gesetzt) in der Saison des Spieltags — Body: { matchday_id, club_id }; 409 wenn completed oder (vor kickoff_date und nicht Admin); gibt created-Count + existing-Liste zurück',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player_rating/validate-csv',
                        'description' => 'CSV-Punkte mit DB-Punkten vergleichen — multipart/form-data: matchday_id + csv-Datei (Semikolon-getrennt, Spalte 4 = Angezeigter Name, Spalte 8 = Punkte); gibt {ok: true, checked: N} oder {ok: false, mismatches: [{displayname, csv_points, db_points}]} zurück — Maintainer+',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/player_rating/:id',
                        'description' => 'Einzelne Bewertung aktualisieren — Body: beliebige Kombination aus grade, participation, goals, assists, clean_sheet, sds, red_card, yellow_red_card; optionales _contribution_type (bulk_create|manual_create, default manual_create) bei participation-Änderungen; points wird immer serverseitig berechnet — Maintainer+',
                        'path_params' => [':id' => 'UUID der player_rating-Zeile'],
                    ],
                ],
            ]),

            new Route('team_rating', 'TeamRating', [
                'title' => 'TeamRating',
                'description' => 'Team-Bewertungen pro Spieltag — letzter abgeschlossener Spieltag der Saison',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/team_rating',
                        'description' => 'Ratings aller Teams für den letzten gestarteten Spieltag — gibt { matchday, ratings[], sds_player, max_matchday_number } zurück; ratings[] enthält red_cards (echte Platzverweise) und yellow_red_cards (Gelb-Rote Karten) als separate Felder; bei nicht-abgeschlossenem Spieltag werden Live-Punkte aus player_rating + team_lineup berechnet (fine = 0)',
                        'query_params' => ['season_id' => 'UUID der Saison (erforderlich)'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/team_rating/season',
                        'description' => 'Saisontabelle — aggregierte Summen (Punkte, Tore, Assists, SdS, total_red_cards, total_yellow_red_cards, …) aller Teams, sortiert nach Punkten',
                        'query_params' => ['season_id' => 'UUID der Saison (erforderlich)'],
                    ],
                ],
            ]),

            new Route('achievement', 'Achievement', [
                'title' => 'Achievement',
                'description' => 'Achievements — alle Definitionen mit earned-Status für den aktuellen Manager',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/achievement',
                        'description' => 'Alle Achievements mit earned_at (null = nicht verdient) für den eingeloggten Manager — Auth; ?all=true → Alle Achievements inkl. threshold_bronze/silver/gold und Manager-Liste mit earned-Status — Admin',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/achievement/evaluate',
                        'description' => 'Achievement-Auswertung für alle Manager anstoßen (Backfill) — Admin; /:id → Einzelnes Achievement neu auswerten inkl. Entzug bei nicht mehr erfüllten Anforderungen — Admin',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/achievement/seen',
                        'description' => 'Alle noch nicht gesehenen Achievements (seen_at IS NULL) des eingeloggten Managers als gesehen markieren — Auth',
                    ],
                ],
            ]),

            new Route('notification', 'Notification', [
                'title' => 'Notification',
                'description' => 'In-App-Benachrichtigungen — Manager-to-Manager oder Systemnachrichten',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/notification',
                        'description' => 'Alle Benachrichtigungen des eingeloggten Managers, neueste zuerst — Auth',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/notification/:id',
                        'description' => 'Einzelne Notification als gelesen markieren (read_at = NOW()); 403 wenn nicht eigene — Auth',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/notification/read_all',
                        'description' => 'Alle ungelesenen Notifications des eingeloggten Managers als gelesen markieren — Auth',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/notification',
                        'description' => 'Neue Notification erstellen {receiver_id, title, message?, sender_id?}; sender_id=null → Systemnachricht — Admin',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/notification/preferences',
                        'description' => 'Benachrichtigungs-Einstellungen des eingeloggten Managers — {matchday_completed: bool, achievement_earned: bool}; fehlende Einträge = true (default ON) — Auth',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/notification/preferences',
                        'description' => 'Einzelne Präferenz setzen — Body: {event_type: matchday_completed|achievement_earned, enabled: bool} — Auth',
                    ],
                ],
            ]),

            new Route('color', 'Color', [
                'title' => 'Color',
                'description' => 'Globale Farbpalette — lesbar ohne Auth, Hex-Änderung kaskadiert auf team.color aller Teams in dieser Liga',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/color',
                        'description' => 'Alle Farben der Palette [{id, name, hex}] — kein Auth erforderlich',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/color/:id',
                        'description' => 'Hex-Wert einer Farbe ändern — kaskadiert automatisch auf team.color aller Teams, die diese Farbe nutzen — Admin',
                        'path_params' => [':id' => 'Name der Farbe (PK), z.B. "red"'],
                        'body' => ['hex' => '#rrggbb (erforderlich)'],
                    ],
                ],
            ]),

            new Route('award', 'Award', [
                'title' => 'Award',
                'description' => 'Award-Typen und Gewinner pro Saison',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/award',
                        'description' => 'Alle Awards mit Gewinnern pro Saison inkl. Statistikwerte (total_points, total_gap, min_matchday_points) am winner-Objekt — Auth',
                    ],
                ],
            ]),

            new Route('transaction', 'Transaction', [
                'title' => 'Transaction',
                'description' => 'Kontoauszug und Budget eines Teams — nur eigenes Team abrufbar (Datenschutz)',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/transaction',
                        'description' => 'Budget und alle Transaktionen des eigenen Teams — gibt { budget, transactions[] } zurück; 403 bei fremdem Team — Auth',
                        'query_params' => ['team_id' => 'UUID des Teams (erforderlich)'],
                    ],
                ],
            ]),

            new Route('sell', 'Sell', [
                'title' => 'Sell',
                'description' => 'Spieler aus eigenem Team verkaufen — nur während offenem Transferfenster',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/sell',
                        'description' => 'Spieler verkaufen: erstellt sell + transaction, schließt player_in_team, entfernt nur den eigenen team_lineup-Eintrag des Spielers für alle noch nicht abgeschlossenen Spieltage (nicht nur den des Transferfensters) — übrige nominierte Spieler bleiben unverändert stehen, es entsteht ggf. eine Lücke in der Formation statt eines Bank-Resets — Auth',
                        'body' => ['team_id' => 'UUID des Teams', 'player_id' => 'UUID des Spielers', 'transferwindow_id' => 'UUID des offenen Transferfensters'],
                    ],
                ],
            ]),

            new Route('buy', 'Buy', [
                'title' => 'Buy',
                'description' => 'Spieler für eigenes Team kaufen — nur während offenem Transferfenster',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/buy',
                        'description' => 'Spieler kaufen: erstellt player_in_team + transaction (negativ) — 409 wenn Spieler bereits in einem Team, noch nicht verfügbar (soon_available, siehe /player_in_season/available_players) oder Positionslimit erreicht — Auth',
                        'body' => ['team_id' => 'UUID des Teams', 'player_id' => 'UUID des Spielers', 'transferwindow_id' => 'UUID des offenen Transferfensters'],
                    ],
                ],
            ]),

            new Route('offer', 'Offer', [
                'title' => 'Offer',
                'description' => 'Gebote auf vereinslose Spieler abgeben — nur während offenem Transferfenster',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/offer',
                        'description' => 'Eigene Gebote abrufen + pending_sum (?team_id) — oder alle Gebote einer geschlossenen Transferphase (?transferwindow_id); triggert Lazy Settlement falls noch pending-Gebote vorhanden — Auth',
                        'query_params' => [
                            'team_id' => 'UUID des Teams → eigene Gebote + pending_sum; jedes Gebot enthält displayname, position, photo_uploaded, club_id, club_logo_uploaded, season_id, losers (für success/lost: [{team_id,team_color,team_season_id,is_winner}]); stornierte Gebote (status=cancelled) werden nicht zurückgegeben',
                            'transferwindow_id' => 'UUID der Transferphase → alle Gebote gruppiert nach Spieler; 422 wenn Fenster noch offen',
                        ],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/offer',
                        'description' => 'Gebot abgeben — 409 wenn Spieler in Team, noch nicht verfügbar (soon_available, siehe /player_in_season/available_players) oder Positionslimit erreicht (inkl. offene Gebote; GK≤2, DEF≤6, MID≤6, FWD≤4), 422 wenn Fenster zu / Gebot < Marktwert / Budget überschritten — Auth',
                        'body' => [
                            'team_id' => 'UUID des Teams',
                            'player_id' => 'UUID des Spielers',
                            'transferwindow_id' => 'UUID des offenen Transferfensters',
                            'offer_value' => 'Gebotswert (INT, min. Marktwert)',
                        ],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/offer/:id',
                        'description' => 'Gebotswert eines pending-Gebots ändern — 422 wenn < Marktwert oder Budget überschritten — nur eigenes Team — Auth',
                        'body' => [
                            'team_id' => 'UUID des Teams',
                            'offer_value' => 'Neuer Gebotswert (INT, min. Marktwert)',
                        ],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/offer/:id',
                        'description' => 'Offenes Gebot stornieren (status → cancelled) — nur eigenes Team — Auth',
                        'body' => ['team_id' => 'UUID des Teams'],
                    ],
                ],
            ]),

            new Route('team', 'Team', [
                'title' => 'Team',
                'description' => 'Fantasy-Teams pro Manager und Saison',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/team',
                        'description' => 'Alle Teams einer Saison — gibt [{id,team_name,color,color_secondary,season_id,manager_id,manager_name,alias,squad_valid,total_value,position_counts:{GOALKEEPER,DEFENDER,MIDFIELDER,FORWARD}}] sortiert nach team_name zurück; squad_valid = Mindestanforderungen erfüllt (GK≥1/DEF≥5/MID≥5/FWD≥3), position_counts = aktuelle Kaderbesetzung je Position — Auth',
                        'query_params' => ['season_id' => 'UUID der Saison (erforderlich)'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/team/mine',
                        'description' => 'Eigenes Team der aktiven Saison — gibt { id, team_name, season_id, color } zurück; 404 wenn kein Team vorhanden — Auth',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/team/:id',
                        'description' => 'Ein Team per ID — enthält manager_name, alias, total_points, matchdays_played. Mit ?include_ratings=1 zusätzlich alle team_ratings sortiert nach matchday_number',
                        'path_params' => [':id' => 'UUID des Teams'],
                        'query_params' => ['include_ratings' => '1 → ratings[] anhängen'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/team',
                        'description' => 'Team für die aktive Saison anlegen — {team_name, color_name?, color_secondary_name?} → {id}; color_name referenziert global.color.name; benachrichtigt alle Admins per E-Mail; 409 wenn Manager bereits ein Team hat — Auth',
                        'body' => ['team_name' => 'string (required)', 'color_name' => 'Name aus GET /color, z.B. "red" (optional)', 'color_secondary_name' => 'Name aus GET /color (optional)'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/team/previous',
                        'description' => 'Letztes Team des eingeloggten Managers aus einer Vorsaison — {id,team_name,color,season_id}; 404 wenn kein Vorsaison-Team vorhanden — Auth',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/team/check-name',
                        'description' => 'Prüft ob ein Teamname in der aktiven Saison verfügbar ist — { available: bool }; 400 wenn Name < 3 Zeichen — Auth',
                        'query_params' => ['name' => 'Teamname (min. 3 Zeichen)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/team/:id/logo',
                        'description' => 'Team-Logo hochladen (multipart/form-data, Feld "image", PNG) — nur eigenes Team — Auth',
                        'path_params' => [':id' => 'UUID des Teams'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/team/:id/logo/takeover',
                        'description' => 'Übernimmt das Logo aus dem Vorsaison-Team desselben Managers für dieses Team — nur eigenes Team; 404 wenn kein Vorsaison-Team — Auth',
                        'path_params' => [':id' => 'UUID des Teams'],
                    ],
                ],
            ]),

            new Route('saisonvorschau', 'Saisonvorschau', [
                'title' => 'Saisonvorschau',
                'description' => 'Kader-Übersicht vor/während Saisonstart im Vergleich zur Vorsaison',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/saisonvorschau',
                        'description' => 'Alle Teams der aktiven Saison mit Kaderzusammensetzung — gibt { season_id, previous_season_id, available, kickoff_date, teams:[{id,team_name,color,color_secondary,manager_id,manager_name,alias,squad_valid,position_counts:{GOALKEEPER,DEFENDER,MIDFIELDER,FORWARD},previous_season_points,previous_season_points_value11,previous_season_points_best11,points_breakdown:{all,value11,best11}:[{name,points,position,club_id,club_logo_uploaded},...],newcomer_count,newcomer_players:[{name,club_id,club_logo_uploaded},...]}], promoted_clubs:[{id,name,short_name,logo_uploaded}], promoted_club_teams:[{team_id,team_name,color,color_secondary,count,players:[{name,club_id,club_logo_uploaded},...]}], special_clubs:[{id,name,logo_uploaded}], special_club_teams:[{team_id,team_name,color,color_secondary,count,players:[{name,club_id,club_logo_uploaded},...]}] } zurück; nur verfügbar bis zum Anpfiff des 1. Spieltags der Liga-Division (kickoff_date) — danach available=false und teams/promoted_clubs/etc. bleiben leer, ohne die Kaderberechnung auszuführen; previous_season_points = Summe der Vorsaison-Punkte aller aktuellen Kaderspieler; previous_season_points_value11 = Vorsaison-Punkte-Summe der 11 (nach Marktwert) teuersten Spieler, die eine der 7 gültigen Formationen ergeben; previous_season_points_best11 = Vorsaison-Punkte-Summe der 11 Spieler, die unter allen gültigen Formationen die höchste Punktzahl ergeben; beide null, wenn keine Formation mit dem Kader erreichbar ist (bei squad_valid=true immer erreichbar); points_breakdown = je Modus die zugrundeliegenden Spieler mit Punkten/Position für den Frontend-Tooltip beim Hover über die Punkte-Zahl — all nach Punkten absteigend, value11 nach Marktwert absteigend, best11 nach Position (GOALKEEPER→DEFENDER→MIDFIELDER→FORWARD) sortiert; newcomer_count/newcomer_players = Anzahl bzw. Spieler (alphabetisch, mit aktuellem Verein fürs Logo) der Kaderspieler ohne ein einziges player_rating in der Vorsaison; promoted_clubs = Vereine der Liga-Division, die in der Vorsaison genau eine Division tiefer (level+1) spielten; promoted_club_teams zählt dabei keine Lückenfüller-Spieler (Marktwert exakt 500.000€ in der 1. Liga bzw. 100.000€ in der 2. Liga); promoted_club_teams/special_club_teams = Teams mit Kaderspielern (players analog mit club_id/club_logo_uploaded) dieser bzw. der fest gewählten Vereine "RB Leipzig"/"TSG Hoffenheim", absteigend nach count sortiert (nur count>0); leere Struktur ohne aktive Saison — Auth',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/saisonvorschau/status',
                        'description' => 'Nur { available, kickoff_date } — ob die Saisonvorschau aktuell verfügbar ist (vor Anpfiff Spieltag 1), ohne die komplette Kaderberechnung auszuführen; für die Sichtbarkeit des Saisonvorschau-Menüpunkts im Frontend (analog GET /h2h/status) — Auth',
                    ],
                ],
            ]),

            new Route('manager', 'Manager', [
                'title' => 'Manager',
                'description' => 'Eigenes Manager-Konto verwalten (Profil, Passwort, Account löschen) — Rollenvergabe + Neuanlage/Einladung nur Admin',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/manager',
                        'description' => 'Alle Manager global mit Rollen und Ligen — [{id, manager_name, alias, status, email, last_activity, stadiums_visited, roles[], leagues[{id,name}]}] — stadiums_visited = Anzahl per manager_stadium als besucht markierter Stadien — Admin',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/manager',
                        'description' => 'Neuen Manager per E-Mail-Einladung anlegen — Body: { manager_name, first_name?, email, league_id } → { id, invite_link }; status=invited (zufälliges, unbenutzbares Platzhalter-Passwort), manager_league sofort status=active (Liga bereits vom Admin zugewiesen, kein separater Einladungs-Schritt nötig); sendet Einladungs-Mail mit Link (7 Tage gültig) zu /login/accept-invite — nach Passwort-Setzen automatischer Login inkl. league_id im JWT; 400 bei fehlenden/ungültigen Feldern, 404 wenn league_id nicht existiert, 409 bei bereits vergebenem manager_name oder email — Admin',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/manager/:id/resend-invite',
                        'description' => 'Einladungslink erneut senden (alter Token wird invalidiert, neuer 7 Tage gültig) → { invite_link } — 404 wenn Manager nicht existiert, 409 wenn status != invited — Admin',
                        'path_params' => [':id' => 'UUID des Managers'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/manager/me',
                        'description' => 'Eigenes Profil abrufen (id, manager_name, alias, roles[], status)',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/manager/leagues',
                        'description' => 'Alle Ligen des eingeloggten Managers — gibt [{id, name, slug}] zurück — Auth',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/manager/:id',
                        'description' => 'Manager per ID — enthält teams[] mit season_id, team_name, total_points, matchdays_played',
                        'path_params' => [':id' => 'UUID des Managers'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/manager/:id/roles',
                        'description' => 'Rollen eines Managers abrufen — gibt roles[] zurück — Admin',
                        'path_params' => [':id' => 'UUID des Managers'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/manager/:id/roles',
                        'description' => 'Rolle hinzufügen — Body: { role: "maintainer"|"admin" } — gibt aktualisierte roles[] zurück — Admin',
                        'path_params' => [':id' => 'UUID des Managers'],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/manager/:id/roles/:role',
                        'description' => 'Rolle entziehen — gibt aktualisierte roles[] zurück — Admin',
                        'path_params' => [':id' => 'UUID des Managers', ':role' => 'Rollenname (maintainer|admin)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/manager/me/photo',
                        'description' => 'Eigenes Profilfoto hochladen (multipart/form-data, Feld "image", JPEG)',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/manager/me',
                        'description' => 'Profil aktualisieren — Body: { current_password, new_password } für Passwort; oder { email } allein für E-Mail-Update (kein Passwort nötig)',
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/manager/me',
                        'description' => 'Konto-Löschung anfragen — Body: { password } — setzt status=deleted und sendet Mail an Admin',
                    ],
                ],
            ]),

            new Route('player_in_season', 'PlayerInSeason', [
                'title' => 'PlayerInSeason',
                'description' => 'Spieler-Saison-Zuordnungen und Auswertungen',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/player_in_season/bundesliga_count',
                        'description' => 'Anzahl der Spieler in der 1. Bundesliga (Level 1, DE) einer Saison',
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/player_in_season/available_players',
                        'description' => 'Alle Bundesliga-Spieler der aktiven Saison ohne Fantasy-Team — {players[{id,displayname,position,price,season_points,photo_uploaded,club_id,club_name,club_short_name,club_logo_uploaded,season_id,current_team_id,current_team_name,current_team_season_id,new_on_market,sold_by_team_id,sold_by_team_name,sold_by_team_season_id,soon_available}]}; ?include_all=1 → auch Spieler mit Fantasy-Team, current_team_* dann gesetzt (sonst null); new_on_market=true wenn aktuell vereinslos und entweder während des offenen Transferfensters von einem anderen Team verkauft (sell-Tabelle) oder im direkt vorherigen Fenster soon_available war und jetzt erstmals freigeschaltet ist (jeweils nur für genau ein Fenster); sold_by_team_* (nur beim Verkaufsfall, sonst null) = zuletzt verkaufendes Team; soon_available=true wenn player_in_season.last_updated ≥ Beginn des offenen Transferfensters (Zeile erst seit Fensterbeginn erstellt/geändert) — bleibt in der Antwort enthalten, ist aber bis zum nächsten Fenster nicht kauf-/bietbar (POST /buy, POST /offer → 409)',
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)', 'include_all' => '1 → auch Spieler mit Fantasy-Team einschließen'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player_in_season',
                        'description' => 'Neuen player_in_season Eintrag anlegen → {id}; 409 bei Duplikat (player_id + season_id) — Maintainer+',
                        'body' => ['player_id' => 'UUID', 'season_id' => 'UUID', 'position' => 'GOALKEEPER|DEFENDER|MIDFIELDER|FORWARD', 'price' => 'int (€, 0 < price <= 50.000.000)'],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/player_in_season/:id',
                        'description' => 'Position und/oder Marktwert eines bestehenden Eintrags korrigieren; 404 wenn nicht gefunden — Maintainer+',
                        'path_params' => [':id' => 'UUID des player_in_season-Eintrags'],
                        'body' => ['position' => 'GOALKEEPER|DEFENDER|MIDFIELDER|FORWARD (optional)', 'price' => 'int (€, 0 < price <= 50.000.000, optional)', '_hinweis' => 'mind. eines der beiden Felder erforderlich'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player_in_season/preview_csv',
                        'description' => 'CSV parsen (;-getrennt: ID;Vorname;Nachname;Kurzname;Angezeigter Name;Verein;Position;Marktwert;Punkte;Notendurchschnitt) und gegen player (kicker_id) + club (Name, exakt mit Fuzzy-Fallback) abgleichen; division_id ist optional — bei fehlender division_id wird die Spielklasse per Mehrheitsentscheid aus den in der CSV enthaltenen, club_in_season-bekannten Vereinen automatisch erkannt (division_candidates[{division_id,count}] absteigend sortiert, division_auto_detected=true, division_id=null falls kein einziger Club einer Division zugeordnet werden konnte — dann bleiben rows/missing_players leer und der Aufruf muss mit einer explizit gewählten division_id wiederholt werden); erkennt zusätzlich Positions-/Marktwert-Abweichungen bei bestehenden player_in_season-Einträgen, Club-Abweichungen (aktueller player_in_club vs. CSV-Club), ob der gematchte Club laut club_in_season tatsächlich in der (übergebenen oder erkannten) Spielklasse spielt, und Spieler, die aktuell einem Club dieser Spielklasse zugeordnet sind aber in der CSV fehlen (missing_players); gibt {status,season_id,season_start_date,division_id,division_auto_detected,division_candidates,rows[{kicker_id,csv_*,matched_player_id,matched_displayname,matched_club_id,club_logo_uploaded,already_in_season,importable,existing_player_in_season_id,existing_position,existing_price,position_price_mismatch,current_player_in_club_id,current_club_id,current_club_name,current_club_logo_uploaded,club_mismatch,club_confirmed,club_unresolved,club_missing,division_mismatch,price_too_high,duplicate_candidate_player_id,duplicate_candidate_kicker_id}],missing_players[{player_id,player_in_club_id,displayname,club_id,club_name,club_logo_uploaded}],division_warning,division_mismatch_count,resolved_club_count} zurück; importable ist nur true wenn zusätzlich weder club_mismatch noch club_unresolved noch club_missing noch division_mismatch noch price_too_high vorliegt — club_mismatch = expliziter Widerspruch (aktueller player_in_club-Club bekannt und abweichend vom CSV-Club), club_unresolved = CSV-Vereinsname konnte keinem Club zugeordnet werden (kein exakter/eindeutiger Fuzzy-Treffer), club_missing = CSV-Club aufgelöst und Spieler gematcht, aber kein aktueller player_in_club bekannt (blockiert seit Einführung, da /player_in_season/available_players zwingend einen aktuellen player_in_club-Eintrag voraussetzt — ohne ihn wäre der neu angelegte player_in_season-Eintrag auf dem Transfermarkt unsichtbar), division_mismatch = gematchter Club spielt laut club_in_season der aktiven Saison nachweislich in einer anderen Division als der verwendeten (unbekannt/nicht gepflegt blockiert NICHT), price_too_high = CSV-Marktwert > 50.000.000 € (unrealistisch, würde beim Insert ohnehin an der DECIMAL(10,2)-Spaltengrenze scheitern) — alle fünf verhindern player_in_season-Anlage; club_confirmed ist rein informativ (beide Clubs bekannt und identisch); division_warning = true wenn mehr als die Hälfte der aufgelösten CSV-Clubs (division_mismatch_count von resolved_club_count) nicht zur verwendeten Division gehören — relevant v.a. wenn der Client eine von der Erkennung abweichende division_id übergibt; für Zeilen ohne kicker_id-Treffer wird zusätzlich per exaktem Displaynamen nach einem evtl. bereits vorhandenen Spieler unter anderer kicker_id gesucht (duplicate_candidate_*) — Hinweis auf falsche/geänderte kicker_id in der CSV; schreibt nichts — Maintainer+',
                        'body' => ['csv' => 'multipart/form-data Datei', 'division_id' => 'UUID der Spielklasse (optional — ohne wird sie aus der CSV automatisch erkannt)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player_in_season/import_csv',
                        'description' => 'Erstellt player_in_season-Einträge für die aktive Saison aus bestätigten preview_csv-Zeilen; überspringt Zeilen mit bereits vorhandenem player_in_season (reason=already_in_season), ungültigen Daten (reason=invalid_row) oder unrealistischem Marktwert > 50.000.000 € (reason=price_too_high) statt 409/500 zu werfen — verhindert insbesondere, dass eine einzelne Zeile mit zu hohem Marktwert die gesamte Schleife per DB-Fehler abbricht und alle nachfolgenden Zeilen mit überspringt; gibt {status,season_id,created[{player_id,id}],created_count,skipped[{player_id,reason}]} zurück — Maintainer+',
                        'body' => ['rows' => '[{player_id, position, price}]'],
                    ],
                ],
            ]),

            new Route('team_lineup', 'TeamLineup', [
                'title' => 'TeamLineup',
                'description' => 'Aufstellung eines Teams für einen Spieltag — Auth',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/team_lineup',
                        'description' => 'Aufstellung eines Teams — gibt { matchday, matchdays[], nominated[], bench[] } zurück; jeder Spieler enthält u.a. points (dieser Spieltag) und season_points (Saison-Gesamtpunkte); nominated[] sortiert nach Position dann position_index, bench[] nach Position dann season_points DESC dann price DESC; matchday_id optional (default: aktueller Spieltag nach start_date; Auto-Init wenn noch keine Einträge); bei nicht abgeschlossenem Spieltag werden team_lineup-Einträge von zwischenzeitlich nicht mehr aktiven Spielern (z. B. verkauft) automatisch gelöscht und nicht zurückgegeben, UND eine nicht mehr erreichbare Formation (Sanity-Check, siehe PATCH) automatisch auf Bank zurückgesetzt (nominated=0 für alle) — abgeschlossene Spieltage bleiben unangetastet. Alternativ: player_id + season_id → [{matchday_number, nominated}] für alle Spieltage eines Spielers',
                        'query_params' => [
                            'team_id' => 'UUID des Teams (erforderlich, außer bei player_id + season_id)',
                            'matchday_id' => 'UUID des Spieltags (optional)',
                            'player_id' => 'UUID des Spielers (kombiniert mit season_id)',
                            'season_id' => 'UUID der Saison (kombiniert mit player_id)',
                        ],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/team_lineup',
                        'description' => 'Aufstellung speichern — nur eigenes Team, nur während Editierfenster (start_date ≤ now < kickoff_date); 422 wenn die resultierende Formation durch keine der 7 gültigen Formationen (343/352/433/442/451/532/541) mehr erreichbar ist (Sanity-Check, z.B. zu viele Spieler auf einer Position) — {status:false, message, formation: {GOALKEEPER,DEFENDER,MIDFIELDER,FORWARD}}',
                        'body' => [
                            'team_id' => 'UUID des Teams',
                            'matchday_id' => 'UUID des Spieltags',
                            'players' => '[{ player_id, nominated: bool, position_index: int|null }]',
                        ],
                    ],
                ],
            ]),

            new Route('player_in_team', 'PlayerInTeam', [
                'title' => 'PlayerInTeam',
                'description' => 'Aktueller Kader eines Fantasy-Teams — Auth',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/player_in_team',
                        'description' => 'Alle aktiven Spieler eines Teams (to_matchday_id IS NULL) mit Position, Preis, Saison-Punkten, aktuellem Club; ?include_former=1 → {current, former}; ?player_id → aktuelles Team oder null; ?player_id + ?season_id → Teamhistorie des Spielers in dieser Saison [{team_id,team_name,color,manager_name,alias,from_matchday_number,to_matchday_number,price_paid}] — price_paid = Kaufpreis (aus transaction ermittelt über team_id+from_matchday_id+Reason-Text; null falls keine passende Transaktion gefunden, z.B. bei sehr alten/manuell angelegten Daten)',
                        'query_params' => ['team_id' => 'UUID des Teams (erforderlich)', 'include_former' => '1 → gibt {current, former} zurück', 'player_id' => 'UUID des Spielers', 'season_id' => 'UUID der Saison — kombiniert mit player_id: gibt Teamhistorie zurück'],
                    ],
                ],
            ]),

            new Route('player', 'Player', [
                'title' => 'Player',
                'description' => 'Spieler mit eingebetteten aktuellen Club- und Saisondaten im Detailabruf',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/player',
                        'description' => 'Alle Spieler mit aggregierten Punkten der Saison; oder aktueller Kader eines Clubs (mit Saisonposition) wenn club_id angegeben',
                        'query_params' => [
                            'country_id' => 'ISO Alpha-2 Code (optional)',
                            'season_id' => 'UUID der Saison (optional, default: aktive Saison)',
                            'club_id' => 'UUID des Clubs — gibt aktuellen Kader zurück (player_in_club.to_date IS NULL) mit season_position',
                        ],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/player/:id',
                        'description' => 'Ein Spieler mit aktuellem Club, Saisondaten und allen Spieltagsbewertungen; clubs[] enthält je Eintrag zusätzlich die player_in_club-id; jeder seasons[]-Eintrag enthält soon_available (wie /player_in_season/available_players — true nur für die aktive Saison, wenn deren player_in_season-Zeile seit Beginn des gerade offenen Transferfensters erstellt/geändert wurde; ältere Saisons immer false)',
                        'path_params' => [':id' => 'UUID des Spielers'],
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)'],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/player/:id',
                        'description' => 'Stammdaten eines bestehenden Spielers korrigieren — beliebige Kombination aus first_name, last_name, displayname, country_id, birth_city, date_of_birth, height_cm, weight_kg (mind. eines erforderlich); erstellt keine neuen Spieler (dafür weiterhin nur der CSV-Import bzw. POST /player/create); 400 wenn keine Felder oder displayname leer, 409 bei displayname-Duplikat, 404 wenn Spieler nicht gefunden, 422 wenn height_cm/weight_kg <= 0 — Maintainer+',
                        'path_params' => [':id' => 'UUID des Spielers'],
                        'body' => [
                            'first_name'    => 'string (optional)',
                            'last_name'     => 'string (optional)',
                            'displayname'   => 'string (optional, muss UNIQUE sein)',
                            'country_id'    => 'ISO Alpha-2 Code oder null (optional)',
                            'birth_city'    => 'string oder null (optional)',
                            'date_of_birth' => 'YYYY-MM-DD oder null (optional)',
                            'height_cm'     => 'int > 0 oder null (optional)',
                            'weight_kg'     => 'int > 0 oder null (optional)',
                        ],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player/:id/photo',
                        'description' => 'Spielerfoto hochladen (multipart/form-data, Feld "image", PNG; Body-Feld season_id) — setzt player_in_season.photo_uploaded für diese Saison — Maintainer+; 403 wenn bereits ein Foto für diese Saison existiert und der Aufrufer kein Admin ist (Überschreiben nur Admin)',
                        'path_params' => [':id' => 'UUID des Spielers'],
                        'body' => ['season_id' => 'UUID der Saison'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player/create',
                        'description' => 'Erstellt einen neuen Spieler mit Saison- und optional Club-Zuweisung — gibt {id} zurück; 400 wenn price außerhalb 0 < price <= 50.000.000 — Maintainer+',
                        'body' => [
                            'kicker_id'  => 'int — Kicker-ID (z.B. 30669)',
                            'first_name' => 'string',
                            'last_name'  => 'string',
                            'displayname'=> 'string (muss UNIQUE sein)',
                            'season_id'  => 'UUID der Saison',
                            'position'   => 'GOALKEEPER|DEFENDER|MIDFIELDER|FORWARD',
                            'price'      => 'int — Marktwert in € (0 < price <= 50.000.000)',
                            'club_id'    => 'UUID des Clubs (optional) — erstellt player_in_club-Eintrag',
                            'from_date'  => 'DATE YYYY-MM-DD (optional, default: heute) — Vertragsbeginn',
                        ],
                    ],
                ],
            ]),

            new Route('player_in_club', 'PlayerInClub', [
                'title' => 'Vereinszuordnung',
                'description' => 'Zuordnung eines Spielers zu einem Verein',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/player_in_club',
                        'description' => 'Fügt einem Spieler einen neuen Vereinseintrag hinzu — gibt {id} zurück — Maintainer+',
                        'body' => [
                            'player_id' => 'UUID des Spielers',
                            'club_id'   => 'UUID des Vereins',
                            'from_date' => 'DATE YYYY-MM-DD',
                            'on_loan'   => 'bool (optional, default false)',
                        ],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/player_in_club/:id',
                        'description' => 'Aktualisiert einen bestehenden Vereinseintrag — beliebige Kombination aus {club_id, from_date, to_date, on_loan}, mind. eines erforderlich (400 sonst); to_date=null ist explizit erlaubt (öffnet eine beendete Zugehörigkeit wieder); 404 wenn nicht gefunden, 422 wenn resultierendes to_date vor from_date liegt — Maintainer+',
                        'path_params' => [':id' => 'UUID des player_in_club-Eintrags'],
                        'body' => [
                            'club_id'   => 'UUID des Vereins (optional)',
                            'from_date' => 'DATE YYYY-MM-DD (optional)',
                            'to_date'   => 'DATE YYYY-MM-DD | null (optional)',
                            'on_loan'   => 'bool (optional)',
                        ],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/player_in_club/:id',
                        'description' => 'Löscht einen Vereinseintrag; 404 wenn nicht gefunden — Maintainer+',
                        'path_params' => [':id' => 'UUID des player_in_club-Eintrags'],
                    ],
                ],
            ]),

            new Route('watchlist', 'Watchlist', [
                'title' => 'Watchlist',
                'description' => 'Spieler-Beobachtungsliste eines Teams — privat, nur eigenes Team sichtbar',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/watchlist',
                        'description' => 'Beobachtete Spieler des eigenen Teams mit Spieler- und Clubdaten, season_points (Saisonpunkte) sowie aktuellem Fantasy-Team — Auth',
                        'query_params' => ['team_id' => 'UUID des eigenen Teams (erforderlich)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/watchlist',
                        'description' => 'Spieler zur Beobachtungsliste hinzufügen — gibt {id} zurück — Auth',
                        'body' => ['team_id' => 'UUID des eigenen Teams', 'player_id' => 'UUID des Spielers'],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/watchlist/:id',
                        'description' => 'Spieler von der Beobachtungsliste entfernen — Auth',
                        'path_params' => [':id' => 'UUID des Watchlist-Eintrags'],
                        'body' => ['team_id' => 'UUID des eigenen Teams'],
                    ],
                ],
            ]),

            new Route('powerranking', 'Powerranking', [
                'title' => 'Powerranking',
                'description' => 'Kicker-Stecktabelle: Manager tippen die Endreihenfolge der Fantasy-Teams der aktiven Saison — Auth; 403 wenn league.powerranking_enabled=false (siehe PATCH /league/:id)',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/powerranking',
                        'description' => 'Vor Anpfiff Spieltag 1: { locked:false, season_id, kickoff_date, my_picks:[{team_id,position}], submitted_count, total_managers } — eigener Tipp, andere Tipps unsichtbar; submitted_count/total_managers = wie viele Manager der Saison bereits (irgend)einen Tipp abgegeben haben von wie vielen insgesamt. Nach Anpfiff Spieltag 1 (oder mit ?preview=1 als Admin): { locked:bool, preview:bool, season_id, kickoff_date, standings:[{team_id,team_name,color,manager_name,season_id,total_points,actual_position}], entries:[{manager_id,manager_name,alias,total_deviation,picks:[{team_id,predicted_position,actual_position,deviation}]}] } sortiert nach total_deviation ASC — standings = aktuelle Live-Saisontabelle wie /team_rating/season; actual_position = Standard-Wettkampf-Rang (1224), punktgleiche Teams (z.B. alle 0 Punkte vor Saisonstart) teilen sich denselben Platz statt willkürlich durchnummeriert zu werden; preview=true = Reveal-Ansicht wird nur wegen Admin-Vorschau vor dem eigentlichen Lock gezeigt (locked bleibt false); 403 wenn für die Liga deaktiviert — Auth',
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)', 'preview' => '"1" — Admin sieht die Reveal-Ansicht (alle Tipps + Tabelle) schon vor Anpfiff Spieltag 1; für Nicht-Admins wirkungslos'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/powerranking',
                        'description' => 'Eigenen Tipp abgeben/überschreiben (ersetzt alle vorherigen Picks des Managers für diese Saison komplett) — nur vor Anpfiff Spieltag 1; 403 nach Anpfiff oder wenn für die Liga deaktiviert, 422 wenn picks nicht exakt eine 1..N-Permutation aller aktuellen Teams der Saison ist — Auth',
                        'body' => ['season_id' => 'UUID der Saison', 'picks' => '[{team_id: UUID, position: INT (1..Teamanzahl, je einmal)}]'],
                    ],
                ],
            ]),

            new Route('h2h', 'H2H', [
                'title' => 'H2H',
                'description' => 'Head-to-Head Turniermodus — Gruppenphase + K.o.-Runde — Auth',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/h2h',
                        'description' => 'Turnier-Übersicht: Gruppen mit Standings + Matches, K.o.-Matches — Auth',
                        'query_params' => ['season_id' => 'UUID (optional, default=aktiv)'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/h2h/status',
                        'description' => 'Ob die Liga jemals ein H2H-Turnier generiert hat (beliebige Saison) → {exists} — für die Sichtbarkeit des H2H-Menüpunkts im Frontend — Auth',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/h2h/:id',
                        'description' => 'Match-Detail: beide Teams, Lineups mit Spieler-Einzelpunkten — Auth',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h',
                        'description' => 'Match anlegen → {id} — Admin',
                        'body' => ['season_id' => 'UUID', 'phase' => 'group|quarterfinal|semifinal|final', 'leg' => '1|2', 'home_team_id' => 'UUID', 'away_team_id' => 'UUID', 'matchday_id' => 'UUID', 'group_id' => 'UUID (optional)', 'sort_index' => 'INT (optional)'],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/h2h/:id',
                        'description' => 'Match aktualisieren — Admin',
                        'body' => ['home_team_id' => 'UUID (optional)', 'away_team_id' => 'UUID (optional)', 'matchday_id' => 'UUID (optional)', 'group_id' => 'UUID (optional)', 'sort_index' => 'INT (optional)'],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/h2h/:id',
                        'description' => 'Match löschen — Admin',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h/generate',
                        'description' => 'H2H-Gruppenphase nach festem Template generieren, abhängig von der Team-Zahl der Saison (nur 9 oder 12 Teams unterstützt): 12 Teams → 4 Gruppen à 3, 24 Matches; 9 Teams → 3 Gruppen à 3, 18 Matches; je Snake-Seeding nach Vorjahresrang, auf Spieltage 1–18 der Division der Liga (Fallback: höchste deutsche Division, falls keine gesetzt); 400 wenn dort Spieltage 1–18 fehlen → {status,groups,matches}; sendet allgemeine Gruppen-Notification + individuelle Spiele-Notification an alle Manager — Admin',
                        'body' => ['league_id' => 'UUID', 'season_id' => 'UUID'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h/draw_quarterfinals',
                        'description' => 'Viertelfinale auslosen aus Gruppenständen (nur 12-Team-Format mit 4 Gruppen; Bed.: Spieltag 18 der Liga-Division abgeschlossen, Spieltage 20–27 dort vorhanden) → {matches:8}; sendet Notification an alle Manager — Admin',
                        'body' => ['league_id' => 'UUID', 'season_id' => 'UUID'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h/draw_semifinals',
                        'description' => 'Halbfinale auslosen; 12-Team-Format (4 Gruppen): aus VF-Siegern (Aggregat-Tore, Tiebreaker: Gesamtpunkte; Bed.: Spieltag 27 der Liga-Division abgeschlossen); 9-Team-Format (3 Gruppen): direkt aus Gruppenständen (3 Gruppensieger + bester Gruppenzweiter nach Punkten/Tordifferenz/Toren, Bed.: Spieltag 18 der Liga-Division abgeschlossen); Spieltage 29–32 müssen dort vorhanden sein → {matches:4}; sendet Notification an alle Manager — Admin',
                        'body' => ['league_id' => 'UUID', 'season_id' => 'UUID'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h/draw_final',
                        'description' => 'Finale auslosen aus HF-Siegern (Aggregat-Tore, Tiebreaker: Gesamtpunkte; Bed.: Spieltag 32 der Liga-Division abgeschlossen, Spieltag 34 dort vorhanden) → {matches:1} — Admin',
                        'body' => ['league_id' => 'UUID', 'season_id' => 'UUID'],
                    ],
                ],
            ]),

            new Route('h2h_group', 'H2HGroup', [
                'title' => 'H2H Group',
                'description' => 'H2H-Gruppen verwalten — Admin',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/h2h_group',
                        'description' => 'Alle Gruppen der Saison mit Team-IDs — Auth',
                        'query_params' => ['season_id' => 'UUID (optional, default=aktiv)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h_group',
                        'description' => 'Gruppe anlegen → {id} — Admin',
                        'body' => ['season_id' => 'UUID', 'name' => 'string', 'sort_index' => 'INT (optional)'],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/h2h_group/:id',
                        'description' => 'Gruppe aktualisieren; teams[] ersetzt alle Zuordnungen — Admin',
                        'body' => ['name' => 'string (optional)', 'sort_index' => 'INT (optional)', 'teams' => '[team_id,...] (optional)'],
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/h2h_group/:id',
                        'description' => 'Gruppe + Team-Zuordnungen löschen, Matches behalten (group_id → NULL) — Admin',
                    ],
                ],
            ]),

            new Route('search', 'Search', [
                'title' => 'Search',
                'description' => 'Globale Live-Suche über Player, Club, Team und Manager — Auth',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/search',
                        'description' => 'Sucht in displayname/first_name/last_name (player), name (club), team_name (team), manager_name/alias (manager) — min. 3 Zeichen, max. 8 Treffer pro Typ',
                        'query_params' => ['q' => 'Suchbegriff (min. 3 Zeichen)'],
                    ],
                ],
            ]),
        ];
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function resolveClass(string $endpoint): ?string
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $endpoint) {
                return $route->getClass() . 'Controller';
            }
        }
        return null;
    }

    public function navigate(array $request): _BaseController
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $request['endpoint']) {
                $class = $route->getClass() . 'Controller';
                return new $class;
            }
        }

        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'Endpoint not found']);
        exit;
    }
}
