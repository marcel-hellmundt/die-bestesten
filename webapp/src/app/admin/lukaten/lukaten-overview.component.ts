import { Component, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { LukatenService } from '../../core/lukaten.service';
import { environment } from '../../../environments/environment';

/** Ein Konto in GET /lukaten/overview: Kontostand und woher er kommt. */
interface LukatenAccountRow {
  manager_id: string;
  manager_name: string;
  balance: number;
  start: number;     // Startbonus (einer je Manager und Saison)
  entries: number;   // Lukaten für Einträge
  eur: number;       // gegen Euro gekauft (Stornos abgezogen)
  packs: number;     // für Sticker-Packs ausgegeben (negativ)
  bets: number;      // Tipps: Gewinne minus Einsätze
}

interface LukatenOverview {
  ready: boolean;    // Kontobuch vorhanden (Migration)
  accounts: LukatenAccountRow[];
  totals: {
    in_circulation: number; start: number; entries: number; eur: number; packs: number; bets: number;
    stakes?: number;   // alle Einsätze dieser Konten, auch offene
    payouts?: number;  // alle ausgezahlten Tippgewinne
  } | null;
}

/** Gegenseite der Konten: wohin ausgegebene Lukaten gehen */
interface CounterpartRow {
  kind: 'bank' | 'shop';
  name: string;
  note: string;
  received: number;        // eingenommen
  paid: number | null;     // ausgezahlt (der Shop zahlt nichts aus)
  balance: number;
}

/**
 * /verwaltung/lukaten: alle Lukaten-Konten — wie viele Lukaten im Umlauf sind und woher sie kommen
 * (Startbonus, Einträge, Euro) bzw. wohin sie gehen (Packs, Tipps). Grundlage, um Preise und die
 * Gewinn-Obergrenze beim Tippen festzulegen (siehe docs/lukaten-economy-concept.md).
 */
@Component({
  selector: 'app-lukaten-overview',
  standalone: false,
  templateUrl: './lukaten-overview.component.html',
  styleUrl: './lukaten-overview.component.scss',
})
export class LukatenOverviewComponent {
  private api = inject(ApiService);

  /** undefined = lädt, null = Fehler */
  overview = signal<LukatenOverview | null | undefined>(undefined);
  accounts = computed(() => this.overview()?.accounts ?? []);
  totals = computed(() => this.overview()?.totals ?? null);

  /**
   * Bank = Gegenseite aller Tipps (Einsätze rein, Gewinne raus), Shop = für Sticker-Packs ausgegebene Lukaten.
   * Zusammen mit "Im Umlauf" ergibt das alles, was je entstanden ist (Startbonus + Einträge + Gekauft).
   */
  counterparts = computed<CounterpartRow[]>(() => {
    const t = this.totals();
    if (!t) return [];
    const stakes = t.stakes ?? 0, payouts = t.payouts ?? 0;
    return [
      { kind: 'bank', name: 'Bank', note: 'Gegenseite aller Tipps', received: stakes, paid: payouts, balance: stakes - payouts },
      { kind: 'shop', name: 'Shop', note: 'Sticker-Packs', received: -t.packs, paid: null, balance: -t.packs },
    ];
  });

  constructor() {
    this.api.get<LukatenOverview>('lukaten/overview').subscribe({
      next: o => this.overview.set(o),
      error: () => this.overview.set(null),
    });
  }

  managerPhotoUrl(managerId: string): string {
    return `${environment.imageApiUrl}/manager/${managerId}.jpg`;
  }

  format(v: number | null | undefined): string {
    return LukatenService.format(v);
  }

  /** mit Vorzeichen; 0 als Strich, damit die Tabelle ruhig bleibt */
  signed(v: number): string {
    if (!v) return '–';
    return (v > 0 ? '+' : '−') + LukatenService.format(Math.abs(v));
  }
}
