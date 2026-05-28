<?php
require_once 'color.database.php';
require_once 'country.database.php';
require_once 'league.database.php';
require_once 'all_time_standings.database.php';
require_once 'season.database.php';
require_once 'matchday.database.php';
require_once 'club.database.php';
require_once 'club_in_season.database.php';
require_once 'division.database.php';
require_once 'player.database.php';
require_once 'player_in_club.database.php';
require_once 'transferwindow.database.php';
require_once 'player_rating.database.php';
require_once 'player_in_season.database.php';
require_once 'manager.database.php';
require_once 'team_rating.database.php';
require_once 'password_reset.database.php';
require_once 'award.database.php';
require_once 'achievement_conditions.database.php';
require_once 'achievement.database.php';
require_once 'player_in_team.database.php';
require_once 'team_lineup.database.php';
require_once 'transaction.database.php';
require_once 'sell.database.php';
require_once 'buy.database.php';
require_once 'offer.database.php';
require_once 'search.database.php';
require_once 'notification.database.php';
require_once 'watchlist.database.php';
require_once 'h2h.database.php';

class Database
{
    use ColorTrait;
    use CountryTrait;
    use LeagueTrait;
    use PlayerInTeamTrait;
    use TeamLineupTrait;
    use TransactionTrait;
    use SellTrait;
    use BuyTrait;
    use OfferTrait;
    use AllTimeStandingsTrait;
    use AwardTrait;
    use AchievementConditionsTrait;
    use AchievementTrait;
    use SeasonTrait;
    use MatchdayTrait;
    use ClubTrait;
    use ClubInSeasonTrait;
    use DivisionTrait;
    use PlayerTrait;
    use PlayerInClubTrait;
    use TransferwindowTrait;
    use PlayerRatingTrait;
    use PlayerInSeasonTrait;
    use ManagerTrait;
    use TeamRatingTrait;
    use PasswordResetTrait;
    use SearchTrait;
    use NotificationTrait;
    use WatchlistTrait;
    use H2HTrait;

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
        $host     = $_ENV['DB_HOST'];
        $user     = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASSWORD'];

        $this->con        = $this->createConnection($host, $_ENV['DB_NAME'], $user, $password);
        $this->con_league = $this->createConnection($host, $_ENV['DB_NAME_LEAGUE'], $user, $password);
        $this->con_old    = $this->createConnection($host, $_ENV['DB_NAME_OLD'], $user, $password);
        $this->ensureManagerView();
    }

    // Called by guard after JWT decode to switch to the correct league DB
    public function switchLeagueConnection(string $leagueId): bool
    {
        $dbName = $this->getManagerLeagueDbName($leagueId);
        if (!$dbName) return false;
        $this->con_league = $this->createConnection(
            $_ENV['DB_HOST'], $dbName, $_ENV['DB_USER'], $_ENV['DB_PASSWORD']
        );
        $this->ensureManagerView();
        return true;
    }

    // Creates a VIEW named `manager` in the league DB pointing to global manager table.
    // This lets all existing con_league queries that JOIN/FROM manager work without changes.
    private function ensureManagerView(): void
    {
        $globalDb = $_ENV['DB_NAME'];
        $this->con_league->exec(
            "CREATE OR REPLACE VIEW manager AS SELECT * FROM `$globalDb`.manager"
        );
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

    protected const SQUAD_MAX = [
        'GOALKEEPER' => 2,
        'DEFENDER'   => 6,
        'MIDFIELDER' => 6,
        'FORWARD'    => 4,
    ];

    protected const STATS_SEASON_START = '2017-07-01';

    private static ?array $_colorMap = null;

    protected function resolveColor(?string $name): ?string
    {
        if ($name === null) return null;
        if (self::$_colorMap === null) {
            $rows = $this->con->query("SELECT name, hex FROM color")->fetchAll(PDO::FETCH_ASSOC);
            self::$_colorMap = array_column($rows, 'hex', 'name');
        }
        return self::$_colorMap[$name] ?? $name;
    }

    protected function getActiveSeasonId(): ?string
    {
        $q = $this->con->prepare("SELECT id FROM season WHERE start_date <= CURDATE() ORDER BY start_date DESC LIMIT 1");
        $q->execute();
        return $q->fetchColumn() ?: null;
    }

    protected function getLeagueDivisionId(): ?string
    {
        $leagueId = $GLOBALS['auth_league_id'] ?? null;
        if ($leagueId) {
            $q = $this->con->prepare("SELECT division_id FROM league WHERE id = :id LIMIT 1");
            $q->execute([':id' => $leagueId]);
        } else {
            $q = $this->con->prepare("SELECT division_id FROM league WHERE db_name = :db_name LIMIT 1");
            $q->execute([':db_name' => $_ENV['DB_NAME_LEAGUE']]);
        }
        return $q->fetchColumn() ?: null;
    }

    // Auth — uses global DB (manager table is in global schema)
    public function getAuthManagerById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM manager WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        $manager = $query->fetch(PDO::FETCH_ASSOC);
        if ($manager) $manager['roles'] = $this->fetchManagerRoles($manager['id']);
        return $manager;
    }

    public function touchLastActivity(string $id): void
    {
        $this->con->prepare(
            "UPDATE manager SET last_activity = NOW() WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public function getAuthManagerByNameOrEmail(string $identifier): array|false
    {
        $query = $this->con->prepare(
            "SELECT * FROM manager WHERE manager_name = :identifier OR email = :identifier LIMIT 1"
        );
        $query->execute([':identifier' => $identifier]);
        $manager = $query->fetch(PDO::FETCH_ASSOC);
        if ($manager) $manager['roles'] = $this->fetchManagerRoles($manager['id']);
        return $manager;
    }

    private function fetchManagerRoles(string $managerId): array
    {
        $q = $this->con->prepare("SELECT role FROM manager_role WHERE manager_id = :id");
        $q->execute([':id' => $managerId]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getManagerLeagueDbName(string $leagueId): string|false
    {
        $q = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $q->execute([':id' => $leagueId]);
        return $q->fetchColumn();
    }

    public function getManagerLeagues(string $managerId): array
    {
        $q = $this->con->prepare(
            "SELECT l.id, l.slug, l.name, ml.status FROM manager_league ml
             JOIN league l ON l.id = ml.league_id
             WHERE ml.manager_id = :manager_id
             ORDER BY l.name"
        );
        $q->execute([':manager_id' => $managerId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function requestJoinLeague(string $managerId, string $leagueId): void
    {
        $this->con->prepare(
            "INSERT INTO manager_league (manager_id, league_id, status) VALUES (:m, :l, 'requested')
             ON DUPLICATE KEY UPDATE status = IF(status = 'denied', 'requested', status)"
        )->execute([':m' => $managerId, ':l' => $leagueId]);
    }

    public function inviteManagerToLeague(string $managerId, string $leagueId): void
    {
        $this->con->prepare(
            "INSERT INTO manager_league (manager_id, league_id, status) VALUES (:m, :l, 'invited')
             ON DUPLICATE KEY UPDATE status = IF(status IN ('denied','requested'), 'invited', status)"
        )->execute([':m' => $managerId, ':l' => $leagueId]);
    }

    public function acceptLeagueInvite(string $managerId, string $leagueId): bool
    {
        $q = $this->con->prepare(
            "UPDATE manager_league SET status = 'active'
             WHERE manager_id = :m AND league_id = :l AND status = 'invited'"
        );
        $q->execute([':m' => $managerId, ':l' => $leagueId]);
        return $q->rowCount() > 0;
    }

    public function declineLeagueInvite(string $managerId, string $leagueId): bool
    {
        $q = $this->con->prepare(
            "UPDATE manager_league SET status = 'denied'
             WHERE manager_id = :m AND league_id = :l AND status = 'invited'"
        );
        $q->execute([':m' => $managerId, ':l' => $leagueId]);
        return $q->rowCount() > 0;
    }

    public function approveMembership(string $managerId, string $leagueId): bool
    {
        $q = $this->con->prepare(
            "UPDATE manager_league SET status = 'active'
             WHERE manager_id = :m AND league_id = :l AND status = 'requested'"
        );
        $q->execute([':m' => $managerId, ':l' => $leagueId]);
        return $q->rowCount() > 0;
    }

    public function denyMembership(string $managerId, string $leagueId): void
    {
        $this->con->prepare(
            "UPDATE manager_league SET status = 'denied' WHERE manager_id = :m AND league_id = :l"
        )->execute([':m' => $managerId, ':l' => $leagueId]);
    }

    public function isManagerInLeague(string $managerId, string $leagueId): bool
    {
        $q = $this->con->prepare(
            "SELECT COUNT(*) FROM manager_league WHERE manager_id = :m AND league_id = :l AND status = 'active'"
        );
        $q->execute([':m' => $managerId, ':l' => $leagueId]);
        return (int) $q->fetchColumn() > 0;
    }

    public function getAdminManagerIds(): array
    {
        $q = $this->con->query("SELECT manager_id FROM manager_role WHERE role = 'admin'");
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }
}
