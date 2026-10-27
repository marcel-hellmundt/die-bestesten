import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { LukatenService } from '../../core/lukaten.service';
import { SYMBOL_NAMES, SlotPayline, SlotSpinResult, SlotSpinRow, SlotState, SlotSymbol, SlotWin } from './slot.model';

/** So oft steht eine Walze hintereinander im Streifen — genug Vorlauf für die längste Drehung (siehe spinTarget) */
const COPIES = 6;
/** Dauer der Drehung je Walze in ms, nacheinander stoppend (bei reduzierter Bewegung kurz) */
const SPIN_MS = [1100, 1550, 2000];
const SPIN_MS_REDUCED = [250, 350, 450];

/** Eine Walze: Streifen aus den Stopps, pos = Index im Streifen, der auf der Gewinnlinie steht */
interface ReelView {
  strip: SlotSymbol[];
  cells: SlotSymbol[]; // der Streifen von unten nach oben, so steht er im DOM (siehe Klassenkommentar)
  pos: number;
  duration: number; // ms; 0 = ohne Animation (zurücksetzen nach einer Drehung)
}

/**
 * /spielhalle — einarmiger Bandit: drei Walzen mit Früchten, Einsatz 1–3 Lukaten vom Lukaten-Konto, gewürfelt wird
 * auf dem Server (POST /slot/spin), die Walzen drehen danach genau auf die gewürfelten Stopps. Gewinn und neuer
 * Kontostand erscheinen erst, wenn die letzte Walze steht. Gewinntabelle, eigene letzte Drehs und Bilanz daneben.
 *
 * Der Streifen jeder Walze steht im DOM von unten nach oben (umgekehrt), damit die Symbole beim Drehen nach unten
 * fallen wie bei einem echten Automaten: ein größerer pos schiebt den Streifen nach unten.
 */
@Component({
  selector: 'app-slot-machine',
  standalone: false,
  templateUrl: './slot-machine.component.html',
  styleUrl: './slot-machine.component.scss',
})
export class SlotMachineComponent {
  private api = inject(ApiService);
  private lukaten = inject(LukatenService);
  private destroyRef = inject(DestroyRef);

  /** undefined = lädt, null = Fehler */
  state = signal<SlotState | null | undefined>(undefined);
  ready = computed(() => this.state()?.ready ?? false);

  reels = signal<ReelView[]>([]);
  stake = signal(1);
  /** Kontostand am Automaten — der Einsatz geht beim Drehen sofort ab, der Gewinn kommt erst, wenn die Walzen stehen */
  balance = signal<number | null>(null);
  spinning = signal(false);
  /** Ergebnis der letzten Drehung, sobald die Walzen stehen */
  lastWin = signal<SlotWin | null>(null);
  lastSpun = signal(false);
  error = signal<string | null>(null);
  /** kurz nach einem Jackpot: Feier-Effekt am Automaten */
  celebrating = signal(false);

  recent = signal<SlotSpinRow[]>([]);
  stats = signal({ spins: 0, staked: 0, won: 0 });

  canSpin = computed(() => this.ready() && !this.spinning() && (this.balance() ?? 0) >= this.stake());
  stakes = computed(() => this.state()?.stakes ?? [1, 2, 3]);
  paytable = computed(() => this.state()?.paytable ?? []);
  jackpot = computed(() => this.state()?.jackpot ?? null);
  /** Bilanz am Automaten: Gewinne minus Einsätze */
  saldo = computed(() => this.stats().won - this.stats().staked);

  private reducedMotion = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  private timers: ReturnType<typeof setTimeout>[] = [];

  constructor() {
    this.destroyRef.onDestroy(() => this.timers.forEach(clearTimeout));
    this.api.get<SlotState>('slot').subscribe({
      next: s => {
        this.state.set(s);
        this.balance.set(s.balance);
        this.recent.set(s.recent);
        this.stats.set(s.stats);
        // Walzen stehen wie nach dem letzten eigenen Dreh, sonst irgendwo
        const stops = s.recent[0]?.stops ?? s.reels.map(r => Math.floor(Math.random() * r.length));
        this.reels.set(s.reels.map((reel, i) => {
          const strip = Array.from({ length: COPIES }, () => reel).flat();
          return { strip, cells: [...strip].reverse(), pos: reel.length + (stops[i] ?? 0), duration: 0 };
        }));
        if (s.ready) this.lukaten.set(s.balance);
      },
      error: () => this.state.set(null),
    });
  }

  setStake(stake: number): void {
    if (!this.spinning()) this.stake.set(stake);
  }

  spin(): void {
    if (!this.canSpin()) return;
    const stake = this.stake();
    this.spinning.set(true);
    this.error.set(null);
    this.lastWin.set(null);
    this.lastSpun.set(false);
    this.balance.update(b => (b ?? 0) - stake);

    this.api.post<SlotSpinResult>('slot/spin', { stake }).subscribe({
      next: r => this.animate(r, stake),
      error: err => {
        this.spinning.set(false);
        this.balance.update(b => (b ?? 0) + stake);
        this.error.set(err?.error?.message ?? 'Der Dreh hat nicht geklappt');
      },
    });
  }

  /** Walzen auf die gewürfelten Stopps drehen, danach Gewinn zeigen und die Walzen unauffällig zurücksetzen */
  private animate(r: SlotSpinResult, stake: number): void {
    const durations = this.reducedMotion ? SPIN_MS_REDUCED : SPIN_MS;
    this.reels.update(reels => reels.map((reel, i) => {
      const length = reel.strip.length / COPIES;
      // jede Walze ein paar Runden weiter als die vorige, landet aber im Streifen (Kopien 3–5)
      return { ...reel, pos: (3 + i) * length + r.stops[i], duration: durations[i] };
    }));

    const done = Math.max(...durations) + 80;
    this.timers.push(setTimeout(() => {
      // zurück in die zweite Kopie — sieht gleich aus, lässt aber wieder Platz für die nächste Drehung
      this.reels.update(reels => reels.map((reel, i) => ({ ...reel, pos: reel.strip.length / COPIES + r.stops[i], duration: 0 })));
      this.spinning.set(false);
      this.lastSpun.set(true);
      this.lastWin.set(r.win);
      this.balance.set(r.balance);
      this.lukaten.set(r.balance);
      this.recent.update(list => [{
        stops: r.stops, symbols: r.symbols, stake, win_key: r.win?.key ?? null, payout: r.payout,
        created_at: new Date().toISOString(),
      }, ...list].slice(0, 10));
      this.stats.update(s => ({ spins: s.spins + 1, staked: s.staked + stake, won: s.won + r.payout }));
      if (r.win?.jackpot || r.win?.key === 'seven3') {
        this.celebrating.set(true);
        this.timers.push(setTimeout(() => this.celebrating.set(false), 3500));
      }
    }, done));
  }

  /**
   * Verschiebung des (umgekehrt im DOM stehenden) Streifens: die Zelle pos steht in der mittleren Reihe des
   * Fensters. In Zellenhöhen, das SCSS rechnet mit --cell.
   */
  offset(reel: ReelView): number {
    return reel.strip.length - 2 - reel.pos;
  }

  /** Gewinn einer Zeile der Tabelle bei diesem Einsatz (Jackpot beim Höchsteinsatz) */
  payout(line: SlotPayline, stake: number): number {
    const j = this.jackpot();
    return j && line.key === j.key && stake === j.stake ? j.payout : line.multiplier * stake;
  }

  isJackpotCell(line: SlotPayline, stake: number): boolean {
    const j = this.jackpot();
    return !!j && line.key === j.key && stake === j.stake;
  }

  /** Symbole einer Zeile für die Anzeige: drei gleiche, drei gemischte bzw. zwei von links + beliebig */
  lineSymbols(line: SlotPayline): (SlotSymbol | null)[] {
    switch (line.match) {
      case 'three': return [line.symbols[0], line.symbols[0], line.symbols[0]];
      case 'any3':  return line.symbols.slice(0, 3);
      case 'left2': return [line.symbols[0], line.symbols[0], null];
    }
  }

  lineLabel(line: SlotPayline): string {
    switch (line.match) {
      case 'three': return `3× ${SYMBOL_NAMES[line.symbols[0]]}`;
      case 'any3':  return `3 beliebige aus ${line.symbols.map(s => SYMBOL_NAMES[s]).join(', ')}`;
      case 'left2': return `2× ${SYMBOL_NAMES[line.symbols[0]]} von links`;
    }
  }

  symbolName(s: SlotSymbol): string {
    return SYMBOL_NAMES[s];
  }

  format(v: number | null | undefined): string {
    return LukatenService.format(v);
  }

  formatSigned(v: number): string {
    return (v > 0 ? '+' : v < 0 ? '−' : '') + LukatenService.format(Math.abs(v));
  }
}
