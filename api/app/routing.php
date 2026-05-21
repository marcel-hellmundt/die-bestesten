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
                        'description' => 'Passwort zurücksetzen — Body: { token, new_password } — Token aus dem Reset-Link; 400 wenn ungültig/abgelaufen',
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
                        'description' => 'Alle Ligen, alphabetisch sortiert — enthält manager_count aus der jeweiligen Liga-DB',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/league/mine',
                        'description' => 'Aktuelle Liga des Deployments {id,slug,name,db_name,division_id}; 404 wenn nicht konfiguriert',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/league/:id',
                        'description' => 'Eine Liga per ID',
                        'path_params' => [':id' => 'UUID der Liga'],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/league/:id',
                        'description' => 'Spielerpool-Division der Liga setzen — Body: {division_id: UUID|null} — Admin',
                        'path_params' => [':id' => 'UUID der Liga'],
                        'body' => ['division_id' => 'CHAR(36) UUID oder null (kein Filter)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/:id/join',
                        'description' => 'Liga beitreten — trägt den eingeloggten Manager in manager_league ein; 404 wenn Liga nicht gefunden — Auth',
                        'path_params' => [':id' => 'UUID der Liga'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/league/migrate',
                        'description' => 'Teams aus der alten DB in die Liga-DB migrieren — Body: { league_id } — Admin',
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
                'description' => 'Spieltage einer Saison',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/matchday',
                        'description' => 'Alle Spieltage, optional gefiltert nach Saison; mit season_id: enthält has_ratings (bool)',
                        'query_params' => ['season_id' => 'UUID der Saison (optional)'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/matchday/:id',
                        'description' => 'Ein Spieltag per ID',
                        'path_params' => [':id' => 'UUID des Spieltags'],
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/matchday/:id',
                        'description' => 'completed-Status setzen — Body: { completed: bool }; bei completed=true: team_rating + Transaktionen für alle Teams erstellen, Achievements auswerten, Notifications senden, Zusammenfassungs-E-Mail an alle Admins mit hinterlegter E-Mail-Adresse senden — Admin',
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
                        'description' => 'Alle Clubs, optional gefiltert nach Land',
                        'query_params' => ['country_id' => 'ISO Alpha-2 Code (optional)'],
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/club/:id',
                        'description' => 'Ein Club per ID — enthält aktuelles Stadion als stadium-Objekt (oder null)',
                        'path_params' => [':id' => 'UUID des Clubs'],
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
                        'description' => 'Alle Transferfenster, optional gefiltert nach Spieltag oder Saison',
                        'query_params' => [
                            'matchday_id' => 'UUID des Spieltags (optional)',
                            'season_id' => 'UUID der Saison (optional) — gibt alle TF der Saison zurück',
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
                        'description' => 'Neues Transferfenster anlegen — Body: { matchday_id, start_date, end_date } — Maintainer+',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/transferwindow/migrate',
                        'description' => 'Migriert Transferfenster aus der alten DB — gibt migrated-Count zurück (nur Admin)',
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
                        'description' => 'Beste valide 11 für einen Spieltag (Formationen 343/352/433/442/451, maximale Gesamtpunkte) — gibt {formation, players[{player_id,displayname,position,points,grade,club_id,club_name,club_short_name}], total_points} zurück; free_agents_only=1 schließt Spieler in Fantasy-Teams aus — Auth',
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
                        'description' => 'Erstellt leere Ratings für alle aktuellen Spieler eines Clubs — Body: { matchday_id, club_id }; 409 wenn completed oder (vor kickoff_date und nicht Admin); gibt created-Count + existing-Liste zurück; neue Ratings werden mit gleicher ID in alte DB gespiegelt',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player_rating/validate-csv',
                        'description' => 'CSV-Punkte mit DB-Punkten vergleichen — multipart/form-data: matchday_id + csv-Datei (Semikolon-getrennt, Spalte 4 = Angezeigter Name, Spalte 8 = Punkte); gibt {ok: true, checked: N} oder {ok: false, mismatches: [{displayname, csv_points, db_points}]} zurück — Maintainer+',
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/player_rating/:id',
                        'description' => 'Einzelne Bewertung aktualisieren — Body: beliebige Kombination aus grade, participation, goals, assists, clean_sheet, sds, red_card, yellow_red_card; optionales _contribution_type (bulk_create|manual_create, default manual_create) bei participation-Änderungen; points wird immer serverseitig berechnet; Änderungen gespiegelt — Maintainer+',
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
                        'description' => 'Spieler verkaufen: erstellt sell + transaction, schließt player_in_team, bereinigt team_lineup — Auth',
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
                        'description' => 'Spieler kaufen: erstellt player_in_team + transaction (negativ) — 409 wenn Spieler bereits in einem Team oder Positionslimit erreicht — Auth',
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
                            'team_id' => 'UUID des Teams → eigene Gebote + pending_sum; jedes Gebot enthält displayname, position, photo_uploaded, club_id, club_logo_uploaded, season_id, losers (für success/lost: [{team_id,team_color,team_season_id,is_winner}])',
                            'transferwindow_id' => 'UUID der Transferphase → alle Gebote gruppiert nach Spieler; 422 wenn Fenster noch offen',
                        ],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/offer',
                        'description' => 'Gebot abgeben — 409 wenn Spieler in Team oder Positionslimit erreicht (inkl. offene Gebote; GK≤2, DEF≤6, MID≤6, FWD≤4), 422 wenn Fenster zu / Gebot < Marktwert / Budget überschritten — Auth',
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
                        'description' => 'Alle Teams einer Saison — gibt [{id,team_name,color,color_secondary,season_id,manager_id,manager_name,alias}] sortiert nach team_name zurück — Auth',
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
                        'description' => 'Team für die aktive Saison anlegen — {team_name, color_name?, color_secondary_name?} → {id}; color_name referenziert global.color.name; 409 wenn Manager bereits ein Team hat — Auth',
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
                ],
            ]),

            new Route('manager', 'Manager', [
                'title' => 'Manager',
                'description' => 'Eigenes Manager-Konto verwalten (Profil, Passwort, Account löschen) — Rollenvergabe nur Admin',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/manager',
                        'description' => 'Alle Manager global mit Rollen und Ligen — [{id, manager_name, alias, status, roles[], leagues[{id,name}]}] — Admin',
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
                        'description' => 'Alle Bundesliga-Spieler der aktiven Saison ohne Fantasy-Team — {players[{id,displayname,position,price,season_points,photo_uploaded,club_id,club_name,club_short_name,club_logo_uploaded,season_id}]}',
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player_in_season',
                        'description' => 'Neuen player_in_season Eintrag anlegen → {id}; 409 bei Duplikat (player_id + season_id) — Maintainer+',
                        'body' => ['player_id' => 'UUID', 'season_id' => 'UUID', 'position' => 'GOALKEEPER|DEFENDER|MIDFIELDER|FORWARD', 'price' => 'int (€, > 0)'],
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
                        'description' => 'Aufstellung eines Teams — gibt { matchday, matchdays[], nominated[], bench[] } zurück; matchday_id optional (default: aktueller Spieltag nach start_date; Auto-Init wenn noch keine Einträge). Alternativ: player_id + season_id → [{matchday_number, nominated}] für alle Spieltage eines Spielers',
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
                        'description' => 'Aufstellung speichern — nur eigenes Team, nur während Editierfenster (start_date ≤ now < kickoff_date)',
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
                        'description' => 'Alle aktiven Spieler eines Teams (to_matchday_id IS NULL) mit Position, Preis, Saison-Punkten, aktuellem Club; ?include_former=1 → {current, former}; ?player_id → aktuelles Team oder null; ?player_id + ?season_id → Teamhistorie des Spielers in dieser Saison [{team_id,team_name,color,manager_name,alias,from_matchday_number,to_matchday_number}]',
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
                        'description' => 'Ein Spieler mit aktuellem Club, Saisondaten und allen Spieltagsbewertungen',
                        'path_params' => [':id' => 'UUID des Spielers'],
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player/migrate',
                        'description' => 'Migriert player, player_in_season, player_in_club und player_rating aus der alten DB — gibt migrated/skipped-Counts zurück — Admin',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/player/create',
                        'description' => 'Erstellt einen neuen Spieler mit Saison- und optional Club-Zuweisung — gibt {id} zurück — Maintainer+',
                        'body' => [
                            'kicker_id'  => 'int — Kicker-ID (z.B. 30669)',
                            'first_name' => 'string',
                            'last_name'  => 'string',
                            'displayname'=> 'string (muss UNIQUE sein)',
                            'season_id'  => 'UUID der Saison',
                            'position'   => 'GOALKEEPER|DEFENDER|MIDFIELDER|FORWARD',
                            'price'      => 'int — Marktwert in €',
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
                ],
            ]),

            new Route('watchlist', 'Watchlist', [
                'title' => 'Watchlist',
                'description' => 'Spieler-Beobachtungsliste eines Teams — privat, nur eigenes Team sichtbar',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/watchlist',
                        'description' => 'Beobachtete Spieler des eigenen Teams mit Spieler- und Clubdaten sowie aktuellem Fantasy-Team — Auth',
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
                        'description' => 'H2H-Gruppenphase nach festem Template generieren (Snake-Seeding nach Vorjahresrang, 4 Gruppen à 3, 24 Matches auf Spieltage 1–18) → {status,groups,matches}; sendet allgemeine Gruppen-Notification + individuelle Spiele-Notification an alle Manager — Admin',
                        'body' => ['league_id' => 'UUID', 'season_id' => 'UUID'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h/draw_quarterfinals',
                        'description' => 'Viertelfinale auslosen aus Gruppenständen (Bed.: Spieltag 18 abgeschlossen) → {matches:8}; sendet Notification an alle Manager — Admin',
                        'body' => ['league_id' => 'UUID', 'season_id' => 'UUID'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h/draw_semifinals',
                        'description' => 'Halbfinale auslosen aus VF-Siegern (Aggregat-Tore, Tiebreaker: Gesamtpunkte; Bed.: Spieltag 27 abgeschlossen) → {matches:4}; sendet Notification an alle Manager — Admin',
                        'body' => ['league_id' => 'UUID', 'season_id' => 'UUID'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/h2h/draw_final',
                        'description' => 'Finale auslosen aus HF-Siegern (Aggregat-Tore, Tiebreaker: Gesamtpunkte; Bed.: Spieltag 32 abgeschlossen) → {matches:1} — Admin',
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
