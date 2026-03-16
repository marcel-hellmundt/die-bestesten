<?php
require_once 'country.database.php';
require_once 'season.database.php';

class Database
{
    use CountryTrait;
    use SeasonTrait;

    private $con;

    protected static $_instance = null;

    public static function getInstance(): self
    {
        if (null === self::$_instance) {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    protected function __clone() {}

    protected function __construct()
    {
        $this->connect();
    }

    private function connect(): void
    {
        try {
            $host     = $_ENV['DB_HOST'];
            $name     = $_ENV['DB_NAME'];
            $user     = $_ENV['DB_USER'];
            $password = $_ENV['DB_PASSWORD'];

            $this->con = new PDO(
                "mysql:host=$host;dbname=$name;charset=utf8",
                $user,
                $password
            );
            $this->con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => false, 'message' => 'Database connection failed']);
            exit;
        }
    }

    public function close(): void
    {
        $this->con = null;
    }

    // Auth — used by guard; requires manager table (league schema)
    public function getAuthManagerById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM manager WHERE manager_id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }
}
