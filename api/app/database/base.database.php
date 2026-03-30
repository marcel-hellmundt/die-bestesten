<?php
require_once 'country.database.php';
require_once 'season.database.php';
require_once 'matchday.database.php';
require_once 'club.database.php';
require_once 'club_in_season.database.php';
require_once 'division.database.php';
require_once 'player.database.php';
require_once 'transferwindow.database.php';
require_once 'player_rating.database.php';
require_once 'player_in_season.database.php';
require_once 'manager.database.php';
require_once 'password_reset.database.php';

class Database
{
    use CountryTrait;
    use SeasonTrait;
    use MatchdayTrait;
    use ClubTrait;
    use ClubInSeasonTrait;
    use DivisionTrait;
    use PlayerTrait;
    use TransferwindowTrait;
    use PlayerRatingTrait;
    use PlayerInSeasonTrait;
    use ManagerTrait;
    use PasswordResetTrait;

    private $con;
    private $con_league;
    private $con_old;

    protected static $_instance = null;

    public static function getInstance(): self
    {
        if (null === self::$_instance) {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    protected function __clone()
    {
    }

    protected function __construct()
    {
        $host = $_ENV['DB_HOST'];
        $user = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASSWORD'];

        $this->con = $this->createConnection($host, $_ENV['DB_NAME'], $user, $password);
        $this->con_league = $this->createConnection($host, $_ENV['DB_NAME_LEAGUE'], $user, $password);
        $this->con_old = $this->createConnection($host, $_ENV['DB_NAME_OLD'], $user, $password);
    }

    private function createConnection(string $host, string $name, string $user, string $password): \PDO
    {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8", $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => false, 'message' => 'Database connection failed', 'error' => $e]);
            exit;
        }
    }

    public function close(): void
    {
        $this->con = null;
        $this->con_league = null;
        $this->con_old = null;
    }

    // Auth — used by guard; requires manager table (league schema)
    public function getAuthManagerById(string $id): array|false
    {
        $query = $this->con_league->prepare("SELECT * FROM manager WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function getAuthManagerByName(string $name): array|false
    {
        $query = $this->con_league->prepare("SELECT * FROM manager WHERE manager_name = :name LIMIT 1");
        $query->execute([':name' => $name]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }
}
