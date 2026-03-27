<?php

// Load environment variables
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
    [$key, $value] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($value);
}

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/guard.php';
require_once __DIR__ . '/app/routing.php';

// CORS
$allowedOrigins = [
    'https://die-bestesten.de',
    'https://data.die-bestesten.de',
    'https://beta.die-bestesten.de',
    'https://claude.die-bestesten.de',
    'http://localhost:4200',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Parse URL: /v1/{endpoint}/{id}
$path     = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$segments = array_values(array_filter(explode('/', $path)));
// $segments[0] = endpoint, $segments[1] = id (optional)

$request = [
    'endpoint' => $segments[0] ?? '',
    'id'       => $segments[1] ?? null,
];

// Load controllers and resolve route
foreach (glob(__DIR__ . '/app/controller/*.controller.php') as $file) {
    require_once $file;
}

$routing         = new Routing();
$controllerClass = $routing->resolveClass($request['endpoint']);

// Auth
$guard      = new Guard();
$authResult = $guard->authorize($controllerClass);

if (!$authResult['status']) {
    http_response_code($authResult['code'] ?? 401);
    echo json_encode($authResult);
    exit;
}

$controller = $routing->navigate($request);
$controller->setRequest($request);

try {
    echo json_encode($controller->getResponse());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => false,
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
}
