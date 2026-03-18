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
                        'description' => 'Ein Club per ID',
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
                ],
            ]),

            new Route('player', 'Player', [
                'title'       => 'Player',
                'description' => 'Spieler mit eingebetteten aktuellen Club- und Saisondaten im Detailabruf',
                'endpoints'   => [
                    [
                        'method'       => 'GET',
                        'path'         => '/player',
                        'description'  => 'Alle Spieler mit aggregierten Punkten der Saison',
                        'query_params' => [
                            'country_id' => 'ISO Alpha-2 Code (optional)',
                            'season_id'  => 'UUID der Saison (optional, default: aktive Saison)',
                        ],
                    ],
                    [
                        'method'       => 'GET',
                        'path'         => '/player/:id',
                        'description'  => 'Ein Spieler mit aktuellem Club, Saisondaten und allen Spieltagsbewertungen',
                        'path_params'  => [':id' => 'UUID des Spielers'],
                        'query_params' => ['season_id' => 'UUID der Saison (optional, default: aktive Saison)'],
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
