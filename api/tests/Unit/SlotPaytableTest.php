<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/database/slot.database.php';

/**
 * Rechnet den Automaten der Spielhalle (SlotTrait) über alle Kombinationen der Walzen nach: Auszahlungsquote und
 * Trefferquote. Bei jeder Änderung an slotConfig() die erwarteten Werte hier anpassen.
 * Ausführen: cd api && vendor/bin/phpunit tests/Unit/SlotPaytableTest.php
 */
class SlotPaytableTest extends TestCase
{
    private object $slot;

    protected function setUp(): void
    {
        $this->slot = new class {
            use SlotTrait { slotConfig as public; slotEvaluate as public; }
        };
    }

    /** [Auszahlungsquote, Trefferquote] bei diesem Einsatz über alle Kombinationen. */
    private function rates(int $stake): array
    {
        [$a, $b, $c] = $this->slot->slotConfig()['reels'];
        $paid = 0;
        $hits = 0;
        foreach ($a as $x) foreach ($b as $y) foreach ($c as $z) {
            $win = $this->slot->slotEvaluate([$x, $y, $z], $stake);
            if ($win === null) continue;
            $hits++;
            $paid += $win['payout'];
        }
        $combos = count($a) * count($b) * count($c);
        return [$paid / ($combos * $stake), $hits / $combos];
    }

    public function testReelsHaveTwentyStopsWithTheSameSymbols(): void
    {
        $counts = ['seven' => 1, 'bell' => 2, 'plum' => 3, 'orange' => 4, 'lemon' => 5, 'cherry' => 2, 'blank' => 3];
        foreach ($this->slot->slotConfig()['reels'] as $reel) {
            $this->assertCount(20, $reel);
            $actual = array_count_values($reel);
            ksort($actual);
            ksort($counts);
            $this->assertSame($counts, $actual);
        }
    }

    public function testReturnToPlayer(): void
    {
        [$rtp1, $hit] = $this->rates(1);
        [$rtp2]       = $this->rates(2);
        [$rtp3]       = $this->rates(3);
        $this->assertEqualsWithDelta(0.855, $rtp1, 0.001);
        $this->assertEqualsWithDelta(0.855, $rtp2, 0.001);
        $this->assertEqualsWithDelta(0.876, $rtp3, 0.001); // Jackpot-Bonus beim Höchsteinsatz
        $this->assertEqualsWithDelta(0.227, $hit, 0.001);
    }

    public function testJackpotOnlyWithMaxStake(): void
    {
        $this->assertSame(500, $this->slot->slotEvaluate(['seven', 'seven', 'seven'], 1)['payout']);
        $this->assertSame(1000, $this->slot->slotEvaluate(['seven', 'seven', 'seven'], 2)['payout']);
        $this->assertSame(2000, $this->slot->slotEvaluate(['seven', 'seven', 'seven'], 3)['payout']);
        $this->assertTrue($this->slot->slotEvaluate(['seven', 'seven', 'seven'], 3)['jackpot']);
    }

    public function testLines(): void
    {
        $this->assertSame('fruit3', $this->slot->slotEvaluate(['plum', 'lemon', 'orange'], 1)['key']);
        $this->assertSame('lemon3', $this->slot->slotEvaluate(['lemon', 'lemon', 'lemon'], 1)['key']);
        $this->assertSame('cherry2', $this->slot->slotEvaluate(['cherry', 'cherry', 'bell'], 1)['key']);
        $this->assertNull($this->slot->slotEvaluate(['bell', 'cherry', 'cherry'], 1)); // Kirschen zählen nur von links
        $this->assertNull($this->slot->slotEvaluate(['plum', 'lemon', 'blank'], 1));
    }
}
