import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { LukatenService } from '../../core/lukaten.service';
import { SYMBOL_ICONS, SYMBOL_NAMES, SlotSpinResult, SlotSpinRow, SlotState, SlotSymbol, SlotWin } from './slot.model';

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
 * /spielo — einarmiger Bandit: drei Walzen mit Früchten, Einsatz 1–3 Lukaten vom Lukaten-Konto, gewürfelt wird
 * auf dem Server (POST /slot/spin), die Walzen drehen danach genau auf die gewürfelten Stopps. Gewinn und neuer
 * Kontostand erscheinen erst, wenn die letzte Walze steht. Eigene letzte Drehs und Bilanz daneben.
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

  readonly isAdmin = inject(AuthService).isAdmin();
  /**
   * Test-Drehs (nur Admin): gewürfelt wie echt, aber nichts gespeichert, kein Einsatz abgebucht, kein Gewinn
   * gutgeschrieben. Ohne eingerichtete Spielhalle (Migration fehlt) für Admins immer an.
   */
  testMode = signal(false);
  /** Bilanz der Test-Drehs dieses Besuchs */
  testStats = signal({ spins: 0, staked: 0, won: 0 });

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

  canSpin = computed(() => (this.ready() || this.testMode()) && !this.spinning()
    && (this.testMode() || (this.balance() ?? 0) >= this.stake()));
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
        else if (this.isAdmin) this.testMode.set(true);
      },
      error: () => this.state.set(null),
    });
  }

  setTestMode(on: boolean): void {
    if (!this.spinning() && this.ready()) this.testMode.set(on);
  }

  setStake(stake: number): void {
    if (!this.spinning()) this.stake.set(stake);
  }

  spin(): void {
    if (!this.canSpin()) return;
    const stake = this.stake();
    const test = this.testMode();
    this.spinning.set(true);
    this.error.set(null);
    this.lastWin.set(null);
    this.lastSpun.set(false);
    if (!test) this.balance.update(b => (b ?? 0) - stake);

    this.api.post<SlotSpinResult>('slot/spin', { stake, test }).subscribe({
      next: r => this.animate(r, stake),
      error: err => {
        this.spinning.set(false);
        if (!test) this.balance.update(b => (b ?? 0) + stake);
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
      if (r.test) {
        // Test-Dreh: Konto, Verlauf und Bilanz bleiben unberührt
        this.testStats.update(s => ({ spins: s.spins + 1, staked: s.staked + stake, won: s.won + r.payout }));
      } else {
        this.balance.set(r.balance);
        this.lukaten.set(r.balance);
        this.recent.update(list => [{
          stops: r.stops, symbols: r.symbols, stake, win_key: r.win?.key ?? null, payout: r.payout,
          created_at: new Date().toISOString(),
        }, ...list].slice(0, 10));
        this.stats.update(s => ({ spins: s.spins + 1, staked: s.staked + stake, won: s.won + r.payout }));
      }
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

  symbolName(s: SlotSymbol): string {
    return SYMBOL_NAMES[s];
  }

  symbolSrc(s: SlotSymbol): string {
    return SYMBOL_ICONS[s];
  }

  format(v: number | null | undefined): string {
    return LukatenService.format(v);
  }

  formatSigned(v: number): string {
    return (v > 0 ? '+' : v < 0 ? '−' : '') + LukatenService.format(Math.abs(v));
  }
}
