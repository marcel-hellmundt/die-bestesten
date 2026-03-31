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
                        'description'  => 'Alle Spieltage, optional gefiltert nach Saison',
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
                        'description'  => 'Alle Ratings eines Clubs an einem Spieltag (mit Spieler-Infos)',
                        'query_params' => [
                            'matchday_id' => 'UUID des Spieltags (erforderlich)',
                            'club_id'     => 'UUID des Clubs (erforderlich)',
                        ],
                    ],
                    [
                        'method'      => 'POST',
                        'path'        => '/player_rating/init',
                        'description' => 'Erstellt leere Ratings für alle aktuellen Spieler eines Clubs — Body: { matchday_id, club_id }; gibt created-Count + existing-Liste zurück',
                    ],
                    [
                        'method'      => 'PATCH',
                        'path'        => '/player_rating/:id',
                        'description' => 'Einzelne Bewertung aktualisieren — Body: beliebige Kombination aus grade, participation, goals, assists, clean_sheet, sds, red_card, yellow_red_card, points',
                        'path_params' => [':id' => 'UUID der player_rating-Zeile'],
                    ],
                ],
            ]),

            new Route('manager', 'Manager', [
                'title'       => 'Manager',
                'description' => 'Eigenes Manager-Konto verwalten (Profil, Passwort, Account löschen)',
                'endpoints'   => [
                    [
                        'method'      => 'GET',
                        'path'        => '/manager/me',
                        'description' => 'Eigenes Profil abrufen (id, manager_name, alias, role, status)',
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
