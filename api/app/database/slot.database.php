<?php

/**
 * Spielhalle: einarmiger Bandit — klassischer 3-Walzen-Automat mit Früchten. Einsatz 1, 2 oder 3 Lukaten vom
 * Lukaten-Konto (LukatenAccountTrait), gewürfelt wird nur hier, ein Gewinn wird sofort gutgeschrieben.
 *
 * Jeder Dreh = eine Zeile slot_spin (Verlauf) + eine Buchung im Kontobuch: source 'slot', Schlüssel slot:{spin_id},
 * Betrag netto (Gewinn − Einsatz). Die Auszahlungsquote liegt bewusst unter 100 % (~85 %) — der Automat zieht
 * Lukaten aus dem Umlauf und wirkt so gegen die Inflation der Pack-Preisstufen. Quote und Trefferquote werden in
 * api/tests/Unit/SlotPaytableTest.php über alle 8.000 Kombinationen nachgerechnet — bei jeder Änderung der Walzen
 * oder der Gewinntabelle dort mitziehen.
 */
trait SlotTrait
{
    /** Walzen, Gewinntabelle, Einsätze — hier an einer Stelle. */
    protected function slotConfig(): array
    {
        return [
            'stakes' => [1, 2, 3],
            // je Walze 20 Stopps in fester Reihenfolge (das Frontend dreht genau auf den gewürfelten Stopp);
            // jede Walze: 1× seven, 2× bell, 3× plum, 4× orange, 5× lemon, 2× cherry, 3× blank
            'reels' => [
                ['lemon', 'orange', 'blank', 'plum', 'lemon', 'cherry', 'orange', 'bell', 'lemon', 'blank',
                 'plum', 'orange', 'seven', 'lemon', 'cherry', 'blank', 'orange', 'plum', 'lemon', 'bell'],
                ['orange', 'lemon', 'cherry', 'plum', 'blank', 'lemon', 'bell', 'orange', 'lemon', 'plum',
                 'seven', 'blank', 'orange', 'lemon', 'cherry', 'plum', 'orange', 'blank', 'lemon', 'bell'],
                ['plum', 'lemon', 'orange', 'bell', 'lemon', 'blank', 'cherry', 'orange', 'lemon', 'seven',
                 'plum', 'blank', 'lemon', 'orange', 'cherry', 'bell', 'lemon', 'orange', 'plum', 'blank'],
            ],
            // Gewinnlinie = Mitte; es zählt die erste passende Zeile (höchste zuerst). multiplier × Einsatz;
            // match: drei gleiche ('three'), drei beliebige aus symbols ('any3'), die ersten beiden von links ('left2')
            'paytable' => [
                ['key' => 'seven3',  'match' => 'three', 'symbols' => ['seven'],  'multiplier' => 500],
                ['key' => 'bell3',   'match' => 'three', 'symbols' => ['bell'],   'multiplier' => 80],
                ['key' => 'plum3',   'match' => 'three', 'symbols' => ['plum'],   'multiplier' => 20],
                ['key' => 'orange3', 'match' => 'three', 'symbols' => ['orange'], 'multiplier' => 12],
                ['key' => 'cherry3', 'match' => 'three', 'symbols' => ['cherry'], 'multiplier' => 10],
                ['key' => 'lemon3',  'match' => 'three', 'symbols' => ['lemon'],  'multiplier' => 8],
                ['key' => 'fruit3',  'match' => 'any3',  'symbols' => ['plum', 'orange', 'lemon'], 'multiplier' => 2],
                ['key' => 'cherry2', 'match' => 'left2', 'symbols' => ['cherry'], 'multiplier' => 4],
            ],
            // Jackpot beim Höchsteinsatz überproportional: 3× seven mit 3 Lukaten zahlt 2000 statt 3 × 500
            'jackpot' => ['key' => 'seven3', 'stake' => 3, 'payout' => 2000],
        ];
    }

    /**
     * Gewinn für die Symbole auf der Gewinnlinie: {key, multiplier, payout, jackpot} oder null.
     * Rein rechnerisch (ohne DB) — auch Grundlage des Tests.
     */
    protected function slotEvaluate(array $symbols, int $stake): ?array
    {
        $cfg = $this->slotConfig();
        foreach ($cfg['paytable'] as $line) {
            $hit = match ($line['match']) {
                'three' => $symbols[0] === $line['symbols'][0] && $symbols[1] === $symbols[0] && $symbols[2] === $symbols[0],
                'any3'  => count(array_intersect($symbols, $line['symbols'])) === 3,
                'left2' => $symbols[0] === $line['symbols'][0] && $symbols[1] === $line['symbols'][0],
                default => false,
            };
            if (!$hit) continue;
            $jackpot = $line['key'] === $cfg['jackpot']['key'] && $stake === $cfg['jackpot']['stake'];
            return [
                'key'        => $line['key'],
                'multiplier' => $line['multiplier'],
                'payout'     => $jackpot ? $cfg['jackpot']['payout'] : $line['multiplier'] * $stake,
                'jackpot'    => $jackpot,
            ];
        }
        return null;
    }

    /** Tabelle slot_spin vorhanden? (Migration 2026-10-27_slot_spin.sql) */
    private function slotReady(): bool
    {
        if (!$this->lukatenLedgerReady()) return false;
        try {
            $this->con->query("SELECT 1 FROM slot_spin LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Ein Dreh aus slot_spin für die Anzeige. */
    private function slotSpinRow(array $r): array
    {
        $reels = $this->slotConfig()['reels'];
        $stops = array_map('intval', explode('|', (string) $r['stops']));
        return [
            'stops'      => $stops,
            'symbols'    => array_map(fn($i, $s) => $reels[$i][$s] ?? 'blank', array_keys($stops), $stops),
            'stake'      => (int) $r['stake'],
            'win_key'    => $r['win_key'],
            'payout'     => (int) $r['payout'],
            'created_at' => $r['created_at'],
        ];
    }

    /**
     * GET /slot — der Automat für die Seite /spielhalle: {ready, balance, stakes, reels, paytable, jackpot,
     * recent (letzte 10 Drehs), stats:{spins, staked, won}}. Ohne Tabelle oder Kontobuch ready=false.
     */
    public function getSlotState(string $managerId): array
    {
        $cfg = $this->slotConfig();
        $base = [
            'stakes'   => $cfg['stakes'],
            'reels'    => $cfg['reels'],
            'paytable' => array_map(fn($l) => ['key' => $l['key'], 'match' => $l['match'], 'symbols' => $l['symbols'], 'multiplier' => $l['multiplier']], $cfg['paytable']),
            'jackpot'  => $cfg['jackpot'],
        ];
        if (!$this->slotReady()) {
            return ['ready' => false, 'balance' => null] + $base + ['recent' => [], 'stats' => ['spins' => 0, 'staked' => 0, 'won' => 0]];
        }

        $rq = $this->con->prepare("SELECT stake, stops, win_key, payout, created_at FROM slot_spin WHERE manager_id = ? ORDER BY created_at DESC, id DESC LIMIT 10");
        $rq->execute([$managerId]);
        $sq = $this->con->prepare("SELECT COUNT(*) AS spins, COALESCE(SUM(stake), 0) AS staked, COALESCE(SUM(payout), 0) AS won FROM slot_spin WHERE manager_id = ?");
        $sq->execute([$managerId]);
        $stats = $sq->fetch(PDO::FETCH_ASSOC);

        return [
            'ready'   => true,
            'balance' => $this->getLukatenBalance($managerId),
        ] + $base + [
            'recent' => array_map(fn($r) => $this->slotSpinRow($r), $rq->fetchAll(PDO::FETCH_ASSOC)),
            'stats'  => ['spins' => (int) $stats['spins'], 'staked' => (int) $stats['staked'], 'won' => (int) $stats['won']],
        ];
    }

    /**
     * POST /slot/spin — einmal drehen. Kontostand prüfen und buchen unter dem Lock je Manager (derselbe wie Pack-Kauf
     * und Tipp-Einsatz), Dreh und Buchung in einer Transaktion.
     * $test (nur Admin, prüft der Controller): Test-Dreh — gewürfelt und ausgewertet wie echt, aber nichts geschrieben,
     * kein Einsatz abgebucht, kein Gewinn gutgeschrieben; geht auch ohne Tabelle und ohne Guthaben.
     * Rückgabe ['error' => HTTP-Code, 'message'] oder {stops, symbols, win, payout, balance, test}.
     */
    public function spinSlot(string $managerId, int $stake, bool $test = false): array
    {
        $cfg = $this->slotConfig();
        if (!in_array($stake, $cfg['stakes'], true)) return ['error' => 422, 'message' => 'Einsatz muss 1, 2 oder 3 Lukaten sein'];
        if ($test) {
            $stops   = array_map(fn($reel) => random_int(0, count($reel) - 1), $cfg['reels']);
            $symbols = array_map(fn($i, $s) => $cfg['reels'][$i][$s], array_keys($stops), $stops);
            $win     = $this->slotEvaluate($symbols, $stake);
            $balance = $this->lukatenLedgerReady() ? $this->getLukatenBalance($managerId) : null;
            return ['stops' => $stops, 'symbols' => $symbols, 'win' => $win, 'payout' => $win['payout'] ?? 0, 'balance' => $balance, 'test' => true];
        }
        if (!$this->slotReady()) return ['error' => 409, 'message' => 'Die Spielhalle ist noch nicht eingerichtet'];
        if (!$this->lukatenLeagues($managerId)) return ['error' => 409, 'message' => 'Du spielst in keiner Liga'];

        $lock = $this->lukatenLockName($managerId);
        $this->con->prepare("SELECT GET_LOCK(?, 5)")->execute([$lock]);
        try {
            $balance = $this->getLukatenBalance($managerId);
            if ($balance + 1e-9 < $stake) {
                return ['error' => 422, 'message' => 'Nicht genug Lukaten'];
            }

            $stops   = array_map(fn($reel) => random_int(0, count($reel) - 1), $cfg['reels']);
            $symbols = array_map(fn($i, $s) => $cfg['reels'][$i][$s], array_keys($stops), $stops);
            $win     = $this->slotEvaluate($symbols, $stake);
            $payout  = $win['payout'] ?? 0;

            $this->con->beginTransaction();
            try {
                $spinId = $this->con->query("SELECT UUID()")->fetchColumn();
                $this->con->prepare(
                    "INSERT INTO slot_spin (id, manager_id, stake, stops, win_key, payout) VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$spinId, $managerId, $stake, implode('|', $stops), $win['key'] ?? null, $payout]);
                if (!$this->bookLukaten($managerId, $payout - $stake, 'slot', "slot:$spinId", $this->getActiveSeasonId())) {
                    throw new \RuntimeException('Lukaten-Buchung wurde nicht angelegt');
                }
                $this->con->commit();
            } catch (\Throwable $e) {
                if ($this->con->inTransaction()) $this->con->rollBack();
                error_log('spinSlot: ' . $e->getMessage());
                return ['error' => 409, 'message' => 'Der Dreh konnte nicht gebucht werden'];
            }
            $balanceAfter = round($balance - $stake + $payout, 2);
        } finally {
            $this->con->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
        }

        return ['stops' => $stops, 'symbols' => $symbols, 'win' => $win, 'payout' => $payout, 'balance' => $balanceAfter, 'test' => false];
    }
}
