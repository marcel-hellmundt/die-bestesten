<?php

class Route
{
    public function __construct(
        private string $name,
        private string $class,
        public readonly array $docs = []
    ) {}

    public function getName(): string  { return $this->name; }
    public function getClass(): string { return $this->class; }
}

class Routing
{
    private array $routes;

    public function __construct()
    {
        $this->routes = [
            new Route('auth', 'Auth', [
                'title'       => 'Auth',
                'description' => 'Authentifizierung — gibt einen JWT zurück (7 Tage gültig)',
                'endpoints'   => [
                    [
                        'method'      => 'POST',
                        'path'        => '/auth',
                        'description' => 'Login mit manager_name und password',
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/auth/password-reset-request',
                        'description' => 'Passwort-Reset anfordern — Body: { email } — sendet Mail mit Reset-Link (1h gültig); gibt immer status:true zurück (kein E-Mail-Leak)',
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/auth/password-reset',
                        'description' => 'Passwort zurücksetzen — Body: { token, new_password } — Token aus dem Reset-Link; 400 wenn ungültig/abgelaufen',
                    ],
                ],
            ]),

            new Route('league', 'League', [
                'title'       => 'League',
                'description' => 'Fantasy-Ligen — jede Liga hat eine eigene Datenbank',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/league',
                        'description' => 'Alle Ligen, alphabetisch sortiert — enthält manager_count aus der jeweiligen Liga-DB',
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/league/:id',
                        'description' => 'Eine Liga per ID',
                        'path_params' => [':id' => 'UUID der Liga'],
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/league/migrate',
                        'description' => 'Teams aus der alten DB in die Liga-DB migrieren — Body: { league_id } — Admin',
                    ],
                ],
            ]),

            new Route('all_time_standings', 'AllTimeStandings', [
                'title'       => 'All-Time Standings',
                'description' => 'Total points per manager across all seasons — Auth',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/all_time_standings',
                        'description' => 'Returns { standings: [{id, manager_name, alias, total_points}], top_matchdays: [{points, matchday_id, matchday_number, team_name, season_id, manager_name}] }',
                    ],
                ],
            ]),

            new Route('country', 'Country', [
                'title'       => 'Country',
                'description' => 'ISO Alpha-2 Ländercodes',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/country',
                        'description' => 'Alle Länder, alphabetisch sortiert',
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/country/:id',
                        'description' => 'Ein Land per ISO Alpha-2 Code',
                        'path_params' => [':id' => 'ISO Alpha-2 Code, z.B. DE'],
                    ],
                ],
            ]),

            new Route('season', 'Season', [
                'title'       => 'Season',
                'description' => 'Saisons — die aktive Saison hat das höchste start_date',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/season',
                        'description' => 'Alle Saisons, neueste zuerst',
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/season/active',
                        'description' => 'Die aktuell aktive Saison',
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/season/:id',
                        'description' => 'Eine Saison per ID',
                        'path_params' => [':id' => 'UUID der Saison'],
                    ],
                ],
            ]),

            new Route('matchday', 'Matchday', [
                'title'       => 'Matchday',
                'description' => 'Spieltage einer Saison',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/matchday',
                        'description'  => 'Alle Spieltage, optional gefiltert nach Saison; mit season_id: enthält has_ratings (bool)',
                        'query_params' => ['season_id' => 'UUID der Saison (optional)'],
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/matchday/:id',
                        'description' => 'Ein Spieltag per ID',
                        'path_params' => [':id' => 'UUID des Spieltags'],
                    ],
                    [
                        'method'      => 'PATCH',
                        'path'        => '/matchday/:id',
                        'description' => 'completed-Status setzen — Body: { completed: bool } (nur Admin)',
                        'path_params' => [':id' => 'UUID des Spieltags'],
                    ],
                ],
            ]),

            new Route('club', 'Club', [
                'title'       => 'Club',
                'description' => 'Fußballvereine',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/club',
                        'description'  => 'Alle Clubs, optional gefiltert nach Land',
                        'query_params' => ['country_id' => 'ISO Alpha-2 Code (optional)'],
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/club/:id',
                        'description' => 'Ein Club per ID — enthält aktuelles Stadion als stadium-Objekt (oder null)',
                        'path_params' => [':id' => 'UUID des Clubs'],
                    ],
                ],
            ]),

            new Route('division', 'Division', [
                'title'       => 'Division',
                'description' => 'Spielklassen (1. Bundesliga, 2. Bundesliga, …)',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/division',
                        'description' => 'Alle Divisionen, sortiert nach Level',
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/division/:id',
                        'description' => 'Eine Division per ID',
                        'path_params' => [':id' => 'UUID der Division'],
                    ],
                ],
            ]),

            new Route('club_in_season', 'ClubInSeason', [
                'title'       => 'ClubInSeason',
                'description' => 'Club-Saison-Zuordnungen mit Division und Tabellenplatz',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/club_in_season',
                        'description'  => 'Einträge nach Club (neueste zuerst) oder nach Saison (nach Platz sortiert)',
                        'query_params' => [
                            'club_id'   => 'UUID des Clubs',
                            'season_id' => 'UUID der Saison',
                        ],
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/club_in_season',
                        'description' => 'Neuen Eintrag anlegen — 409 bei doppelter club_id+season_id Kombination',
                        'query_params' => [
                            'club_id'     => 'UUID des Clubs (erforderlich)',
                            'season_id'   => 'UUID der Saison (erforderlich)',
                            'division_id' => 'UUID der Division (optional)',
                            'position'    => 'Tabellenplatz als Integer, null erlaubt (optional)',
                        ],
                    ],
                    [
                        'method'      => 'PATCH',
                        'path'        => '/club_in_season/:id',
                        'description' => 'Division und/oder Tabellenplatz eines Eintrags aktualisieren',
                        'path_params' => [':id' => 'UUID des Eintrags'],
                        'query_params' => [
                            'division_id' => 'UUID der neuen Division (optional)',
                            'position'    => 'Neuer Tabellenplatz, null erlaubt (optional)',
                        ],
                    ],
                ],
            ]),

            new Route('transferwindow', 'Transferwindow', [
                'title'       => 'Transferwindow',
                'description' => 'Transferfenster je Spieltag — üblicherweise 2, selten 4 pro Spieltag',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/transferwindow',
                        'description'  => 'Alle Transferfenster, optional gefiltert nach Spieltag oder Saison',
                        'query_params' => [
                            'matchday_id' => 'UUID des Spieltags (optional)',
                            'season_id'   => 'UUID der Saison (optional) — gibt alle TF der Saison zurück',
                        ],
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/transferwindow/:id',
                        'description' => 'Ein Transferfenster per ID',
                        'path_params' => [':id' => 'UUID des Transferfensters'],
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/transferwindow',
                        'description' => 'Neues Transferfenster anlegen — Body: { matchday_id, start_date, end_date } — Maintainer+',
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/transferwindow/migrate',
                        'description' => 'Migriert Transferfenster aus der alten DB — gibt migrated-Count zurück (nur Admin)',
                    ],
                ],
            ]),

            new Route('player_rating', 'PlayerRating', [
                'title'       => 'PlayerRating',
                'description' => 'Spieler-Bewertungen pro Spieltag erfassen und abrufen',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/player_rating',
                        'description'  => 'Alle Ratings eines Clubs an einem Spieltag (mit Spieler-Infos inkl. price, starting_count); sortiert nach starting_count DESC, position, price DESC — Auth',
                        'query_params' => [
                            'matchday_id' => 'UUID des Spieltags (erforderlich)',
                            'club_id'     => 'UUID des Clubs (erforderlich)',
                        ],
                    ],
                    [
                        'method'       => 'GET',
                        'path'         => '/player_rating/status',
                        'description'  => 'Aggregierter Bewertungsstatus aller Clubs für einen Spieltag — gibt [{club_id, rating_count, starter_count, grade_count}] zurück',
                        'query_params' => [
                            'matchday_id' => 'UUID des Spieltags (erforderlich)',
                        ],
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/player_rating/init',
                        'description' => 'Erstellt leere Ratings für alle aktuellen Spieler eines Clubs — Body: { matchday_id, club_id }; 409 wenn completed oder (vor kickoff_date und nicht Admin); gibt created-Count + existing-Liste zurück; neue Ratings werden mit gleicher ID in alte DB gespiegelt',
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/player_rating/validate-csv',
                        'description' => 'CSV-Punkte mit DB-Punkten vergleichen — multipart/form-data: matchday_id + csv-Datei (Semikolon-getrennt, Spalte 4 = Angezeigter Name, Spalte 8 = Punkte); gibt {ok: true, checked: N} oder {ok: false, mismatches: [{displayname, csv_points, db_points}]} zurück — Maintainer+',
                    ],
                    [
                        'method'      => 'PATCH',
                        'path'        => '/player_rating/:id',
                        'description' => 'Einzelne Bewertung aktualisieren — Body: beliebige Kombination aus grade, participation, goals, assists, clean_sheet, sds, red_card, yellow_red_card; optionales _contribution_type (bulk_create|manual_create, default manual_create) bei participation-Änderungen; points wird immer serverseitig berechnet; Änderungen gespiegelt — Maintainer+',
                        'path_params' => [':id' => 'UUID der player_rating-Zeile'],
                    ],
                ],
            ]),

            new Route('team_rating', 'TeamRating', [
                'title'       => 'TeamRating',
                'description' => 'Team-Bewertungen pro Spieltag — letzter abgeschlossener Spieltag der Saison',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/team_rating',
                        'description'  => 'Ratings aller Teams für den letzten gestarteten Spieltag — gibt { matchday, ratings[], sds_player, max_matchday_number } zurück; bei nicht-abgeschlossenem Spieltag werden Live-Punkte aus player_rating + team_lineup berechnet (fine = 0)',
                        'query_params' => ['season_id' => 'UUID der Saison (erforderlich)'],
                    ],
                    [
                        'method'       => 'GET',
                        'path'         => '/team_rating/season',
                        'description'  => 'Saisontabelle — aggregierte Summen (Punkte, Tore, Assists, SdS, …) aller Teams, sortiert nach Punkten',
                        'query_params' => ['season_id' => 'UUID der Saison (erforderlich)'],
                    ],
                ],
            ]),

            new Route('award', 'Award', [
                'title'       => 'Award',
                'description' => 'Award-Typen und Gewinner pro Saison',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/award',
                        'description' => 'Alle Awards mit Gewinnern pro Saison inkl. Statistikwerte (total_points, total_gap, min_matchday_points) am winner-Objekt — Auth',
                    ],
                ],
            ]),

            new Route('transaction', 'Transaction', [
                'title'       => 'Transaction',
                'description' => 'Kontoauszug und Budget eines Teams — nur eigenes Team abrufbar (Datenschutz)',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/transaction',
                        'description'  => 'Budget und alle Transaktionen des eigenen Teams — gibt { budget, transactions[] } zurück; 403 bei fremdem Team — Auth',
                        'query_params' => ['team_id' => 'UUID des Teams (erforderlich)'],
                    ],
                ],
            ]),

            new Route('sell', 'Sell', [
                'title'       => 'Sell',
                'description' => 'Spieler aus eigenem Team verkaufen — nur während offenem Transferfenster',
                'endpoints'   => [
                    [
                        'method'      => 'POST',
                        'path'        => '/sell',
                        'description' => 'Spieler verkaufen: erstellt sell + transaction, schließt player_in_team, bereinigt team_lineup — Auth',
                        'body'        => ['team_id' => 'UUID des Teams', 'player_id' => 'UUID des Spielers', 'transferwindow_id' => 'UUID des offenen Transferfensters'],
                    ],
                ],
            ]),

            new Route('buy', 'Buy', [
                'title'       => 'Buy',
                'description' => 'Spieler für eigenes Team kaufen — nur während offenem Transferfenster',
                'endpoints'   => [
                    [
                        'method'      => 'POST',
                        'path'        => '/buy',
                        'description' => 'Spieler kaufen: erstellt player_in_team + transaction (negativ) — 409 wenn Spieler bereits in einem Team oder Positionslimit erreicht — Auth',
                        'body'        => ['team_id' => 'UUID des Teams', 'player_id' => 'UUID des Spielers', 'transferwindow_id' => 'UUID des offenen Transferfensters'],
                    ],
                ],
            ]),

            new Route('offer', 'Offer', [
                'title'       => 'Offer',
                'description' => 'Gebote auf vereinslose Spieler abgeben — nur während offenem Transferfenster',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/offer',
                        'description'  => 'Eigene Gebote abrufen + pending_sum (?team_id) — oder alle Gebote einer geschlossenen Transferphase (?transferwindow_id); triggert Lazy Settlement falls noch pending-Gebote vorhanden — Auth',
                        'query_params' => [
                            'team_id'           => 'UUID des Teams → eigene Gebote + pending_sum; jedes Gebot enthält displayname, position, photo_uploaded, club_id, club_logo_uploaded, season_id, losers (für success/lost: [{team_id,team_color,team_season_id,is_winner}])',
                            'transferwindow_id' => 'UUID der Transferphase → alle Gebote gruppiert nach Spieler; 422 wenn Fenster noch offen',
                        ],
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/offer',
                        'description' => 'Gebot abgeben — 409 wenn Spieler in Team, 422 wenn Fenster zu / Gebot < Marktwert / Budget überschritten — Auth',
                        'body'        => [
                            'team_id'           => 'UUID des Teams',
                            'player_id'         => 'UUID des Spielers',
                            'transferwindow_id' => 'UUID des offenen Transferfensters',
                            'offer_value'       => 'Gebotswert (INT, min. Marktwert)',
                        ],
                    ],
                    [
                        'method'      => 'PATCH',
                        'path'        => '/offer/:id',
                        'description' => 'Gebotswert eines pending-Gebots ändern — 422 wenn < Marktwert oder Budget überschritten — nur eigenes Team — Auth',
                        'body'        => [
                            'team_id'     => 'UUID des Teams',
                            'offer_value' => 'Neuer Gebotswert (INT, min. Marktwert)',
                        ],
                    ],
                    [
                        'method'      => 'DELETE',
                        'path'        => '/offer/:id',
                        'description' => 'Offenes Gebot stornieren (status → cancelled) — nur eigenes Team — Auth',
                        'body'        => ['team_id' => 'UUID des Teams'],
                    ],
                ],
            ]),

            new Route('team', 'Team', [
                'title'       => 'Team',
                'description' => 'Fantasy-Teams pro Manager und Saison',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/team/mine',
                        'description' => 'Eigenes Team der aktiven Saison — gibt { id, team_name, season_id, color } zurück; 404 wenn kein Team vorhanden — Auth',
                    ],
                    [
                        'method'       => 'GET',
                        'path'         => '/team/:id',
                        'description'  => 'Ein Team per ID — enthält manager_name, alias, total_points, matchdays_played. Mit ?include_ratings=1 zusätzlich alle team_ratings sortiert nach matchday_number',
                        'path_params'  => [':id' => 'UUID des Teams'],
                        'query_params' => ['include_ratings' => '1 → ratings[] anhängen'],
                    ],
                ],
            ]),

            new Route('manager', 'Manager', [
                'title'       => 'Manager',
                'description' => 'Eigenes Manager-Konto verwalten (Profil, Passwort, Account löschen) — Rollenvergabe nur Admin',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/manager/me',
                        'description' => 'Eigenes Profil abrufen (id, manager_name, alias, roles[], status)',
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/manager/:id',
                        'description' => 'Manager per ID — enthält teams[] mit season_id, team_name, total_points, matchdays_played',
                        'path_params' => [':id' => 'UUID des Managers'],
                    ],
                    [
                        'method'      => 'GET',
                        'path'        => '/manager/:id/roles',
                        'description' => 'Rollen eines Managers abrufen — gibt roles[] zurück — Admin',
                        'path_params' => [':id' => 'UUID des Managers'],
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/manager/:id/roles',
                        'description' => 'Rolle hinzufügen — Body: { role: "maintainer"|"admin" } — gibt aktualisierte roles[] zurück — Admin',
                        'path_params' => [':id' => 'UUID des Managers'],
                    ],
                    [
                        'method'      => 'DELETE',
                        'path'        => '/manager/:id/roles/:role',
                        'description' => 'Rolle entziehen — gibt aktualisierte roles[] zurück — Admin',
                        'path_params' => [':id' => 'UUID des Managers', ':role' => 'Rollenname (maintainer|admin)'],
                    ],
                    [
                        'method'      => 'PATCH',
                        'path'        => '/manager/me',
                        'description' => 'Profil aktualisieren — Body: { current_password, new_password } für Passwort; oder { email } allein für E-Mail-Update (kein Passwort nötig)',
                    ],
                    [
                        'method'      => 'DELETE',
                        'path'        => '/manager/me',
                        'description' => 'Konto-Löschung anfragen — Body: { password } — setzt status=deleted und sendet Mail an Admin',
                    ],
                ],
            ]),

            new Route('player_in_season', 'PlayerInSeason', [
                'title'       => 'PlayerInSeason',
                'description' => 'Spieler-Saison-Zuordnungen und Auswertungen',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/player_in_season/bundesliga_count',
                        'description'  => 'Anzahl der Spieler in der 1. Bundesliga (Level 1, DE) einer Saison',
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)'],
                    ],
                    [
                        'method'       => 'GET',
                        'path'         => '/player_in_season/available_players',
                        'description'  => 'Alle Bundesliga-Spieler der aktiven Saison ohne Fantasy-Team — {players[{id,displayname,position,price,season_points,photo_uploaded,club_id,club_name,club_short_name,club_logo_uploaded,season_id}]}',
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)'],
                    ],
                ],
            ]),

            new Route('team_lineup', 'TeamLineup', [
                'title'       => 'TeamLineup',
                'description' => 'Aufstellung eines Teams für einen Spieltag — Auth',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/team_lineup',
                        'description'  => 'Aufstellung eines Teams — gibt { matchday, matchdays[], nominated[], bench[] } zurück; matchday_id optional (default: aktueller Spieltag nach start_date; Auto-Init wenn noch keine Einträge). Alternativ: player_id + season_id → [{matchday_number, nominated}] für alle Spieltage eines Spielers',
                        'query_params' => [
                            'team_id'     => 'UUID des Teams (erforderlich, außer bei player_id + season_id)',
                            'matchday_id' => 'UUID des Spieltags (optional)',
                            'player_id'   => 'UUID des Spielers (kombiniert mit season_id)',
                            'season_id'   => 'UUID der Saison (kombiniert mit player_id)',
                        ],
                    ],
                    [
                        'method'      => 'PATCH',
                        'path'        => '/team_lineup',
                        'description' => 'Aufstellung speichern — nur eigenes Team, nur während Editierfenster (start_date ≤ now < kickoff_date)',
                        'body'        => [
                            'team_id'     => 'UUID des Teams',
                            'matchday_id' => 'UUID des Spieltags',
                            'players'     => '[{ player_id, nominated: bool, position_index: int|null }]',
                        ],
                    ],
                ],
            ]),

            new Route('player_in_team', 'PlayerInTeam', [
                'title'       => 'PlayerInTeam',
                'description' => 'Aktueller Kader eines Fantasy-Teams — Auth',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/player_in_team',
                        'description'  => 'Alle aktiven Spieler eines Teams (to_matchday_id IS NULL) mit Position, Preis, Saison-Punkten, aktuellem Club; ?include_former=1 → {current, former}; ?player_id → aktuelles Team oder null; ?player_id + ?season_id → Teamhistorie des Spielers in dieser Saison [{team_id,team_name,color,manager_name,alias,from_matchday_number,to_matchday_number}]',
                        'query_params' => ['team_id' => 'UUID des Teams (erforderlich)', 'include_former' => '1 → gibt {current, former} zurück', 'player_id' => 'UUID des Spielers', 'season_id' => 'UUID der Saison — kombiniert mit player_id: gibt Teamhistorie zurück'],
                    ],
                ],
            ]),

            new Route('player', 'Player', [
                'title'       => 'Player',
                'description' => 'Spieler mit eingebetteten aktuellen Club- und Saisondaten im Detailabruf',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/player',
                        'description'  => 'Alle Spieler mit aggregierten Punkten der Saison; oder aktueller Kader eines Clubs (mit Saisonposition) wenn club_id angegeben',
                        'query_params' => [
                            'country_id' => 'ISO Alpha-2 Code (optional)',
                            'season_id'  => 'UUID der Saison (optional, default: aktive Saison)',
                            'club_id'    => 'UUID des Clubs — gibt aktuellen Kader zurück (player_in_club.to_date IS NULL) mit season_position',
                        ],
                    ],
                    [
                        'method'       => 'GET',
                        'path'         => '/player/:id',
                        'description'  => 'Ein Spieler mit aktuellem Club, Saisondaten und allen Spieltagsbewertungen',
                        'path_params'  => [':id' => 'UUID des Spielers'],
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)'],
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/player/migrate',
                        'description' => 'Migriert player, player_in_season, player_in_club und player_rating aus der alten DB — gibt migrated/skipped-Counts zurück (nur Admin)',
                    ],
                ],
            ]),

            new Route('search', 'Search', [
                'title'       => 'Search',
                'description' => 'Globale Live-Suche über Player, Club, Team und Manager — Auth',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/search',
                        'description'  => 'Sucht in displayname/first_name/last_name (player), name (club), team_name (team), manager_name/alias (manager) — min. 3 Zeichen, max. 8 Treffer pro Typ',
                        'query_params' => ['q' => 'Suchbegriff (min. 3 Zeichen)'],
                    ],
                ],
            ]),
        ];
    }

    public function getRoutes(): array { return $this->routes; }

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
