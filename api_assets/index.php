<?php
#echo phpversion();
#error_reporting(E_ALL);
#ini_set('display_errors', 'On');

require_once("app/guard.php");
require_once("app/routing.php");
require_once("app/database/base.database.php");

$allowedOrigins = [
	'https://die-bestesten.de',
	'https://data.die-bestesten.de',
	'https://beta.die-bestesten.de',
	'https://claude.die-bestesten.de',
	'http://localhost:4200',
	'http://192.168.178.22:4200',
];

$origin = $_SERVER['HTTP_ORIGIN'];
if (in_array($origin, $allowedOrigins)) {
	header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Headers: Access-Control-Allow-Headers, Access-Control-Request-Method, Access-Control-Request-Headers, Origin, Accept, X-Requested-With, Content-Type, Authorization, X-EMAIL, X-PASSWORD');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS, PATCH, DELETE');
header('Content-Type: application/json');

use \Firebase\JWT\JWT;
require __DIR__ . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
	header('HTTP/1.1 200 OK');
	echo json_encode(['status' => 'OK']);
	return;
}

$url = "http://" . $_SERVER['SERVER_NAME'] . '/img' . $_SERVER['REQUEST_URI'];
if (file_exists($url)) {
	header('Content-Type: image/gif');
	$img = file_get_contents($url);
	echo $img;
} else {
	//header('HTTP/1.1 404 Not Found');
	// return ['status' => 'Not Found', 'message' => 'Image not found'];
}

$db = Database::getInstance();
$guard = new Guard();
$authorized = $guard->authorize($request);


if ($authorized['status']) {
	if ($_SERVER['REQUEST_METHOD'] == 'GET') {
		header('Content-Type: image/gif');
		$url = "http://" . $_SERVER['SERVER_NAME'] . '/img' . $_SERVER['REQUEST_URI'];
		$img = file_get_contents($url);
		echo $img;
	} else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		header('Content-Type: application/json');
		$url = urldecode($_SERVER['REQUEST_URI']);
		list($request, $params) = explode('?', $url);
		list($uri, $endpoint, $id) = explode('/', $request);

		$request = [
			"endpoint" => $endpoint,
			"id" => $id,
			"params" => $params,
		];

		$season_id = $_POST['season_id'];
		$player_id = $_POST['player_id'];
		$club_id = $_POST['club_id'];
		$team_id = $_POST['team_id'];

		if ($endpoint == 'player') {
			$target = __DIR__ . '/img/' . $endpoint . '/' . $season_id . '/' . $player_id . '.png';
			$season = $db->getSeasonById($season_id);
			$player = $db->getPlayerById($player_id);
			$db->postActivity('PATCH', $player_id, 'player', 'photo', 'spielerdatenbank', 'Foto von [' . $player['displayname'] . '] in der Saison [' . $season['name'] . '] geändert');
			$db->setPhoto($player_id, $season_id);
			move_uploaded_file($_FILES['image']['tmp_name'], $target);
		} else if ($endpoint == 'club') {
			$target = __DIR__ . '/img/' . $endpoint . '/' . $club_id . '.png';
			$db->setClubPhoto($club_id);
			move_uploaded_file($_FILES['image']['tmp_name'], $target);
		} else if ($endpoint == 'team') {
			$target = __DIR__ . '/img/' . $endpoint . '/' . $season_id . '/' . $team_id . '.png';
			if (!is_dir(dirname($target))) {
				mkdir(dirname($target), 0755, true);
			}
			if ($_POST['takeover'] === 'true') {
				$last_season_id = $_POST['last_season_id'];
				$last_team_id = $_POST['last_team_id'];
				$source = __DIR__ . '/img/' . $endpoint . '/' . $last_season_id . '/' . $last_team_id . '.png';
				copy($source, $target);
			} else {
				move_uploaded_file($_FILES['image']['tmp_name'], $target);
			}
		} else if ($endpoint == 'manager') {
			$manager_id = $_POST['manager_id'];
			if ($manager_id !== $_SERVER['manager_id']) {
				http_response_code(403);
				echo json_encode(['status' => false, 'message' => 'Forbidden']);
				exit;
			}
			$target = __DIR__ . '/img/manager/' . $manager_id . '.jpg';
			if (!is_dir(dirname($target))) {
				mkdir(dirname($target), 0755, true);
			}
			move_uploaded_file($_FILES['image']['tmp_name'], $target);
		}




		echo json_encode($request);
	}

} else {
	header('HTTP/1.1 401 Unauthorized');
	echo json_encode($authorized);
}
?>