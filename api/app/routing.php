<?php

class Route
{
    public function __construct(
        private string $name,
        private string $class
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
            new Route('country',  'Country'),
            new Route('season',   'Season'),
            new Route('matchday', 'Matchday'),
            new Route('club',     'Club'),
        ];
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
