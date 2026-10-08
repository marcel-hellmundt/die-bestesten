import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { ApiService } from '../core/api.service';
import { AuthService } from '../auth/auth.service';
import { LukatenService } from '../core/lukaten.service';
import { Season } from '../core/models/season.model';
import { PackInfo } from '../stickers/album/pack.model';
import { PackKind } from '../stickers/shop/shop.model';

type EntryType = 'participation' | 'note' | 'stats';
type LukatenSource = 'season_bonus' | 'entries' | 'eur' | 'eur_cancel' | 'pack' | 'admin';

interface LukatenTransaction {
  source: LukatenSource;
  amount: number;
  created_at: string;
  season_start: string | null;     // Startbonus: Saison
  matchday_number: number | null;  // Einträge: Spieltag
  pack_name: string | null;        // Pack-Kauf: Pack-Art
  club_name: string | null;        // Vereins-Pack: Verein
}

/** Lukaten gegen Euro: ein Bündel */
interface EurBundle {
  key: string;
  amount_cents: number;
  lukaten: number;
}

/** Eigener, noch nicht bestätigter Lukaten-Kauf (bitte per PayPal bezahlen). */
interface EurPending {
  id: string;
  offer_key: string;
  amount_cents: number;
  code: string;
  created_at: string;
  paypal_url: string;
}

/** Response von POST /lukaten/buy_eur. */
interface EurResult {
  purchase_id: string;
  code: string;
  amount_cents: number;
  paypal_url: string;
  lukaten: number;
  balance: number;
}

/** Response von GET /lukaten/account — ohne Kontobuch (Migration fehlt) nur ready=false. */
interface LukatenAccount {
  ready: boolean;
  balance: number | null;
  totals?: { start: number; entries: number; eur: number; packs: number; stakes: number; payouts: number };
  /** Einträge auf noch nicht abgeschlossenen Spieltagen — werden beim Abschluss gebucht */
  pending_entries?: { count: number; by_type: Record<EntryType, number> };
  transactions?: LukatenTransaction[];
  rules?: {
    season_bonus: number;
    max_payout: number | null;
    packs: { kind: string; name: string; size: number; price: number }[];
    eur_bundles: EurBundle[];
  };
  eur?: { available: boolean; paypal_me: string; pending: EurPending[] };
}

const EUR_MAX_PENDING = 3; // wie im Backend (StickerShopEurTrait), gilt für Packs und Lukaten zusammen

/**
 * Münzstapel der Kauf-Kacheln: je größer das Bündel, desto mehr Münzen — Reihen von unten nach oben
 * (eine · zwei nebeneinander · 2 + 1 · 3 + 2 + 1). Maße in px, müssen zu .bundle__coins im SCSS passen.
 */
const COIN = { size: 26, stepX: 15, stepY: 11, width: 56, height: 48 };
const COIN_STACKS: { x: number; y: number }[][] = [[1], [2], [2, 1], [3, 2, 1]].map(rows =>
  rows.flatMap((count, row) => {
    const rowWidth = (count - 1) * COIN.stepX + COIN.size;
    const left = (COIN.width - rowWidth) / 2;
    const y = COIN.height - COIN.size - row * COIN.stepY;
    return Array.from({ length: count }, (_, j) => ({ x: left + j * COIN.stepX, y }));
  }));

/**
 * /lukaten — die zentrale Stelle für Lukaten (siehe docs/lukaten-economy-concept.md): oben der Kontostand, darunter
 * auf Desktop zwei Spalten über die ganze Breite — links übereinander, woher Lukaten kommen (Einträge, Startbonus,
 * Kauf gegen Euro), rechts die Angebote, sie auszugeben (Sticker-Packs mit Pack-Cover und Preis, Bestico) —, zuletzt
 * der Kontoauszug. Die Regeln zu den Einträgen stehen bewusst knapp da ("1 Eintrag = 1 Lukate"), die Einzelheiten
 * hinter "So wird gezählt".
 */
@Component({
  selector: 'app-lukaten-account',
  standalone: false,
  templateUrl: './lukaten-account.component.html',
  styleUrl: './lukaten-account.component.scss',
})
export class LukatenAccountComponent {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private lukaten = inject(LukatenService);

  /** undefined = lädt, null = Fehler */
  account = signal<LukatenAccount | null | undefined>(undefined);
  /** Noten eintragen dürfen nur Contributor und höher — nur ihnen den Link zeigen */
  readonly canContribute = this.auth.isContributor();

  active = computed(() => {
    const a = this.account();
    return a && a.ready ? a : null;
  });

  /**
   * Kontostand geteilt (z.B. "40" + ",7"): krumme Beträge entstehen durch Tippgewinne (Einsatz × Quote) und spielen
   * bei größeren Guthaben keine Rolle — die Nachkommastellen stehen klein hinter dem ganzen Betrag.
   */
  balanceParts = computed(() => {
    const [whole, fraction] = LukatenService.format(this.active()?.balance).split(',');
    return { whole, fraction: fraction ? ',' + fraction : '' };
  });

  /** Summen fürs Konto: nur Teile mit Bewegung, in fester Reihenfolge */
  totals = computed(() => {
    const t = this.active()?.totals;
    if (!t) return [];
    return [
      { key: 'start', label: 'Startbonus', amount: t.start },
      { key: 'entries', label: 'Einträge', amount: t.entries },
      { key: 'eur', label: 'Gekauft', amount: t.eur },
      { key: 'payouts', label: 'Tippgewinne', amount: t.payouts },
      { key: 'stakes', label: 'Tipp-Einsätze', amount: t.stakes },
      { key: 'packs', label: 'Sticker-Packs', amount: t.packs },
    ].filter(x => x.amount !== 0);
  });

  private readonly entryTypeLabel: Record<EntryType, string> = { participation: 'Einsätze', note: 'Noten', stats: 'Statistik' };

  /** z.B. "Einsätze 70 · Noten 45 · Statistik 5" */
  entryBreakdown(byType: Record<EntryType, number>): string {
    return (['participation', 'note', 'stats'] as EntryType[])
      .filter(t => byType[t] > 0)
      .map(t => `${this.entryTypeLabel[t]} ${this.format(byType[t])}`).join(' · ');
  }

  /** Versatz der Pack-Bewegung je Pack — ungleichmäßig, damit die vier nicht im Takt schweben und glänzen */
  readonly packDelays = ['0s', '-1.7s', '-0.6s', '-2.3s'];

  /** Pack-Arten aus dem Shop mit Preis — samt den Angaben fürs kleine Pack-Cover (app-pack-cover) */
  packOffers = computed(() => (this.active()?.rules?.packs ?? []).map(p => ({
    ...p,
    info: {
      id: null, source: 'shop', size: p.size, milestonePoints: null, matchdayNumber: null, leagueName: null,
      kind: p.kind as PackKind,
    } as PackInfo,
  })));

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.get<LukatenAccount>('lukaten/account').subscribe({
      next: a => { this.account.set(a); if (a.ready) this.lukaten.set(a.balance); },
      error: () => this.account.set(null),
    });
  }

  // ── Lukaten kaufen (Euro per PayPal.me, wie die Euro-Packs im Shop) ──
  eurAvailable = computed(() => this.active()?.eur?.available ?? false);
  eurPending   = computed(() => this.active()?.eur?.pending ?? []);
  paypalMe     = computed(() => this.active()?.eur?.paypal_me ?? '');
  /** Gekaufte Lukaten, die noch auf die Bestätigung der Zahlung warten (noch nicht auf dem Konto) */
  pendingLukaten = computed(() => this.eurPending().reduce((sum, p) => sum + (this.bundleFor(p.offer_key)?.lukaten ?? 0), 0));
  /** Zu viele unbezahlte Käufe → erst bezahlen */
  eurBlocked   = computed(() => this.eurPending().length >= EUR_MAX_PENDING);

  confirming = signal<EurBundle | null>(null);
  buying     = signal(false);
  buyError   = signal<string | null>(null);
  /** Nach dem Kauf: Code + PayPal-Link zum Bezahlen */
  eurResult  = signal<EurResult | null>(null);

  open(b: EurBundle): void {
    this.buyError.set(null);
    this.eurResult.set(null);
    this.confirming.set(b);
  }

  @HostListener('document:keydown.escape')
  closeConfirm(): void {
    if (!this.buying()) this.confirming.set(null);
  }

  buy(): void {
    const b = this.confirming();
    if (!b || this.buying() || this.eurResult()) return;
    this.buying.set(true);
    this.buyError.set(null);
    this.api.post<EurResult>('lukaten/buy_eur', { offer_key: b.key }).subscribe({
      next: r => {
        this.buying.set(false);
        this.eurResult.set(r);
        this.lukaten.set(r.balance);
        this.load();
      },
      error: err => {
        this.buying.set(false);
        this.buyError.set(err?.error?.message ?? 'Kauf fehlgeschlagen');
      },
    });
  }

  bundleFor(key: string): EurBundle | null {
    return this.active()?.rules?.eur_bundles.find(b => b.key === key) ?? null;
  }

  /** Münzen der Kauf-Kachel für das n-te Bündel (aufsteigend nach Größe) — das größte bekommt den höchsten Stapel */
  coinStack(index: number): { x: number; y: number }[] {
    return COIN_STACKS[Math.min(index, COIN_STACKS.length - 1)];
  }

  /** Lukaten je Euro gegenüber dem kleinsten Bündel, in Prozent mehr (0 = kein Vorteil) */
  bonusPct(b: EurBundle): number {
    const bundles = this.active()?.rules?.eur_bundles ?? [];
    if (!bundles.length) return 0;
    const base = bundles[0].lukaten / bundles[0].amount_cents;
    return Math.max(0, Math.round((b.lukaten / b.amount_cents / base - 1) * 100));
  }

  copied = signal<string | null>(null);
  copy(text: string): void {
    navigator.clipboard?.writeText(text).then(() => {
      this.copied.set(text);
      setTimeout(() => this.copied.set(null), 1500);
    }).catch(() => {});
  }

  /** Text einer Buchung im Kontoauszug */
  describe(t: LukatenTransaction): string {
    const season = t.season_start ? Season.from({ id: '', start_date: t.season_start }).longDisplayName : null;
    switch (t.source) {
      case 'season_bonus': return season ? `Startbonus Saison ${season}` : 'Startbonus';
      case 'entries':      return t.matchday_number != null ? `Einträge · Spieltag ${t.matchday_number}` : 'Einträge';
      case 'eur':          return 'Lukaten gekauft';
      case 'eur_cancel':   return 'Kauf storniert';
      case 'pack':         return (t.pack_name ?? 'Sticker-Pack') + (t.club_name ? ` · ${t.club_name}` : '');
      default:             return 'Buchung';
    }
  }

  format(v: number | null | undefined): string {
    return LukatenService.format(v);
  }

  /** mit Vorzeichen, für Summen und Kontoauszug */
  formatSigned(v: number): string {
    return (v > 0 ? '+' : v < 0 ? '−' : '') + LukatenService.format(Math.abs(v));
  }

  formatEur(cents: number): string {
    return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
  }
}
