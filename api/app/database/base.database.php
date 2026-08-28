<?php
require_once 'color.database.php';
require_once 'country.database.php';
require_once 'league.database.php';
require_once 'all_time_standings.database.php';
require_once 'season.database.php';
require_once 'matchday.database.php';
require_once 'club.database.php';
require_once 'club_in_season.database.php';
require_once 'stadium.database.php';
require_once 'manager_stadium.database.php';
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
require_once 'h2h_prediction.database.php';
require_once 'powerranking.database.php';
require_once 'session.database.php';
require_once 'saisonvorschau.database.php';

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
    use StadiumTrait;
    use ManagerStadiumTrait;
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
    use H2HPredictionTrait;
    use PowerrankingTrait;
    use SessionTrait;
    use SaisonvorschauTrait;

    private $con;
    private $con_league;

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
    }

    protected const SQUAD_MAX = [
        'GOALKEEPER' => 2,
        'DEFENDER'   => 6,
        'MIDFIELDER' => 6,
        'FORWARD'    => 4,
    ];

    // [GOALKEEPER, DEFENDER, MIDFIELDER, FORWARD] — the 7 formations a team_lineup/best-XI is
    // allowed to take; GK is always exactly 1. Single source of truth shared by team_lineup
    // validation, best_xi, and the matchday payout calculation.
    protected const VALID_FORMATIONS = [
        [1, 3, 4, 3], [1, 3, 5, 2], [1, 4, 3, 3], [1, 4, 4, 2], [1, 4, 5, 1], [1, 5, 3, 2], [1, 5, 4, 1],
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

    protected function getLeagueFineRuleset(): string
    {
        $leagueId = $GLOBALS['auth_league_id'] ?? null;
        if ($leagueId) {
            $q = $this->con->prepare("SELECT fine_ruleset FROM league WHERE id = :id LIMIT 1");
            $q->execute([':id' => $leagueId]);
        } else {
            $q = $this->con->prepare("SELECT fine_ruleset FROM league WHERE db_name = :db_name LIMIT 1");
            $q->execute([':db_name' => $_ENV['DB_NAME_LEAGUE']]);
        }
        return $q->fetchColumn() ?: 'classic';
    }

    protected function isPowerrankingEnabled(): bool
    {
        $leagueId = $GLOBALS['auth_league_id'] ?? null;
        if ($leagueId) {
            $q = $this->con->prepare("SELECT powerranking_enabled FROM league WHERE id = :id LIMIT 1");
            $q->execute([':id' => $leagueId]);
        } else {
            $q = $this->con->prepare("SELECT powerranking_enabled FROM league WHERE db_name = :db_name LIMIT 1");
            $q->execute([':db_name' => $_ENV['DB_NAME_LEAGUE']]);
        }
        $val = $q->fetchColumn();
        return $val === false ? true : (bool) $val;
    }

    /**
     * Startbudget + Punkte-Bonus der Division, aus der sich die aktuelle Liga bedient (Fallback:
     * höchste deutsche Division, falls die Liga keine division_id konfiguriert hat — dasselbe
     * Fallback-Muster wie in player_in_season.database.php::getAvailablePlayers()). Zentral hier
     * statt an jeder der fünf Aufrufstellen dupliziert, da die Auflösung nicht trivial ist.
     */
    protected function getDivisionConfig(): array
    {
        $divisionId = $this->getLeagueDivisionId();
        if ($divisionId !== null) {
            $q = $this->con->prepare("SELECT starting_budget, points_bonus FROM division WHERE id = :id LIMIT 1");
            $q->execute([':id' => $divisionId]);
        } else {
            $q = $this->con->prepare("SELECT starting_budget, points_bonus FROM division WHERE level = 1 AND LOWER(country_id) = 'de' LIMIT 1");
            $q->execute();
        }
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return [
            'starting_budget' => $row ? (int) $row['starting_budget'] : 50_000_000,
            'points_bonus'    => $row ? (int) $row['points_bonus']    : 20_000,
        ];
    }

    /**
     * True if a player's player_in_season row (position/price) already existed in its current
     * form before the given transfer window's start_date — i.e. it was not created or edited
     * while this window was open. Used to block buy/offer on players that are only visible under
     * the "Bald verfügbar" market filter, independent of whether the caller already knows the
     * player_id (e.g. via /search) rather than getting it from the available_players listing.
     */
    public function isPlayerVisibleInWindow(string $playerId, string $windowId): bool
    {
        $wq = $this->con->prepare(
            "SELECT tw.start_date, m.season_id FROM transferwindow tw
             JOIN matchday m ON m.id = tw.matchday_id WHERE tw.id = :id LIMIT 1"
        );
        $wq->execute([':id' => $windowId]);
        $window = $wq->fetch(PDO::FETCH_ASSOC);
        if (!$window) return false;

        $q = $this->con->prepare(
            "SELECT 1 FROM player_in_season
             WHERE player_id = :pid AND season_id = :sid
               AND (last_updated IS NULL OR last_updated < :start) LIMIT 1"
        );
        $q->execute([':pid' => $playerId, ':sid' => $window['season_id'], ':start' => $window['start_date']]);
        return (bool) $q->fetchColumn();
    }

    /**
     * The transfer window currently open for a season (start_date <= now < end_date), or null.
     * Shared lookup for market-visibility checks (soon_available), used wherever a single
     * player's row needs to be checked against "the window that's open right now" without also
     * needing the previous window (getAvailablePlayers() needs both and keeps its own inline
     * lookup for that reason).
     */
    protected function getCurrentTransferwindow(string $seasonId): ?array
    {
        $windows = $this->getTransferwindowList(null, $seasonId);
        $now     = date('Y-m-d H:i:s');
        foreach ($windows as $w) {
            if ($w['start_date'] <= $now && $now < $w['end_date']) return $w;
        }
        return null;
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
