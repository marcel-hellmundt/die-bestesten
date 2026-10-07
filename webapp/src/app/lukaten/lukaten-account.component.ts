import { Component, computed, inject, signal } from '@angular/core';
import { ApiService } from '../core/api.service';
import { AuthService } from '../auth/auth.service';
import { Season } from '../core/models/season.model';

type EntryType = 'participation' | 'note' | 'stats';
type LukatenSource = 'season_bonus' | 'entries' | 'eur' | 'pack' | 'stake' | 'payout' | 'admin';

interface LukatenTransaction {
  source: LukatenSource;
  amount: number;
  created_at: string;
  season_start: string | null;   // Startbonus: Saison
  pack_name: string | null;      // Pack-Kauf: Pack-Art
  club_name: string | null;      // Vereins-Pack: Verein
}

/** Response von GET /lukaten/account — außerhalb des Konto-Modus nur mode/preview/ready mit balance null. */
interface LukatenAccount {
  mode: 'classic' | 'account';
  preview: boolean;
  ready: boolean;
  balance: number | null;
  totals?: Partial<Record<LukatenSource, { amount: number; bookings: number }>>;
  entries?: {
    since: string | null;        // ab wann Einträge zählen
    per: number;                 // so viele Einträge je Lukate
    count: number;               // gezählte Einträge (nur wer zuerst einträgt)
    by_type: Record<EntryType, number>;
    credited: number;            // daraus gutgeschriebene Lukaten
    to_next: number;             // Einträge bis zur nächsten Lukate
  };
  transactions?: LukatenTransaction[];
  rules?: {
    season_bonus: number;
    entries_per_lukate: number;
    packs: { kind: string; name: string; size: number; price: number }[];
    eur_bundles: { amount_cents: number; lukaten: number }[];
  };
}

/**
 * /lukaten — die zentrale Stelle für Lukaten im neuen Modus (Konto je Manager, siehe
 * docs/lukaten-economy-concept.md): Kontostand, woher Lukaten kommen (Startbonus, Einträge, Kauf), wofür man sie
 * ausgibt, und der Kontoauszug. Im klassischen Modus nur ein Hinweis.
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

  /** undefined = lädt, null = Fehler */
  account = signal<LukatenAccount | null | undefined>(undefined);
  /** Noten eintragen dürfen nur Contributor und höher — nur ihnen den Link zeigen */
  readonly canContribute = this.auth.isContributor();

  active = computed(() => {
    const a = this.account();
    return a && a.mode === 'account' && a.ready ? a : null;
  });

  /** Fortschritt zur nächsten Lukate, 0–100 */
  entryProgress = computed(() => {
    const e = this.active()?.entries;
    if (!e || e.per <= 0) return 0;
    return Math.max(0, Math.min(100, Math.round((e.per - e.to_next) / e.per * 100)));
  });

  /** Summen fürs Konto: nur Quellen mit Bewegung, in fester Reihenfolge */
  totals = computed(() => {
    const t = this.active()?.totals ?? {};
    return (['season_bonus', 'entries', 'eur', 'payout', 'pack', 'stake'] as LukatenSource[])
      .filter(s => t[s])
      .map(s => ({ source: s, label: this.sourceLabel[s], amount: t[s]!.amount }));
  });

  readonly sourceLabel: Record<LukatenSource, string> = {
    season_bonus: 'Startbonus', entries: 'Einträge', eur: 'Gekauft', pack: 'Sticker-Packs',
    stake: 'Tipp-Einsätze', payout: 'Tippgewinne', admin: 'Admin',
  };
  private readonly entryTypeLabel: Record<EntryType, string> = { participation: 'Einsätze', note: 'Noten', stats: 'Statistik' };

  /** z.B. "Einsätze 700 · Noten 450 · Statistik 53" */
  entryBreakdown(byType: Record<EntryType, number>): string {
    return (['participation', 'note', 'stats'] as EntryType[])
      .map(t => `${this.entryTypeLabel[t]} ${this.formatCount(byType[t] ?? 0)}`).join(' · ');
  }

  constructor() {
    this.api.get<LukatenAccount>('lukaten/account').subscribe({
      next: a => this.account.set(a),
      error: () => this.account.set(null),
    });
  }

  /** Text einer Buchung im Kontoauszug */
  describe(t: LukatenTransaction): string {
    if (t.source === 'season_bonus') {
      return t.season_start ? `Startbonus Saison ${Season.from({ id: '', start_date: t.season_start }).longDisplayName}` : 'Startbonus';
    }
    if (t.source === 'pack') {
      return (t.pack_name ?? 'Sticker-Pack') + (t.club_name ? ` · ${t.club_name}` : '');
    }
    return this.sourceLabel[t.source] ?? t.source;
  }

  seasonSince(date: string | null): string {
    return date ? Season.from({ id: '', start_date: date }).longDisplayName : '';
  }

  formatLukaten(v: number | null | undefined): string {
    if (v == null) return '–';
    return Number.isInteger(v) ? v.toLocaleString('de-DE') : v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /** mit Vorzeichen, für Summen und Kontoauszug */
  formatSigned(v: number): string {
    return (v > 0 ? '+' : v < 0 ? '−' : '') + this.formatLukaten(Math.abs(v));
  }

  formatCount(v: number): string {
    return v.toLocaleString('de-DE');
  }

  formatEur(cents: number): string {
    return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
  }
}
