import { Component, DestroyRef, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
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
  slot?: number;     // Spielhalle: Gewinne minus Einsätze
}

/** Preisstufe der Lukaten-Packs nach der Menge im Umlauf (GET /lukaten/overview → price_level) */
interface PriceLevel {
  level: number;                 // aktive Stufe (Index in levels)
  factor: number;
  week_start: string;            // Montag, seit dem sie gilt
  avg_circulation: number | null; // Mittel der 14 Tage vor week_start — Grundlage der Stufe
  stored: boolean;               // false = ohne Tabelle (Migration) direkt aus dem Mittel gerechnet
  avg_now: number | null;        // Mittel der letzten 14 Tage bis heute
  forecast: number;              // voraussichtliche Stufe ab nächstem Montag
  days: number;
  down_margin: number;
  levels: { from: number; to: number | null; factor: number }[];
  packs: { key: string; kind: string; name: string; size: number; base: number; prices: number[] }[];
}

interface LukatenOverview {
  ready: boolean;    // Kontobuch vorhanden (Migration)
  price_level?: PriceLevel;
  accounts: LukatenAccountRow[];
  totals: {
    in_circulation: number; start: number; entries: number; eur: number; packs: number; bets: number;
    slot?: number;     // Spielhalle: Gewinne minus Einsätze aller Konten
    slot_stakes?: number;  // Spielhalle: alle Einsätze
    slot_payouts?: number; // Spielhalle: alle ausgezahlten Gewinne
    stakes?: number;   // alle Einsätze dieser Konten, auch offene
    payouts?: number;  // alle ausgezahlten Tippgewinne
  } | null;
}

/** Response von GET /lukaten/history: Stände am Tagesende seit dem Stichtag */
interface LukatenHistory {
  ready: boolean;
  since: string;
  days: string[];
  managers: { manager_id: string; manager_name: string; balance: number[] }[];
  bank: number[];
  shop: number[];
  arcade?: number[]; // Spielhalle: Einsätze minus ausgezahlte Gewinne
}

/** Eine Fläche im Verlauf-Chart */
interface ChartSeries {
  key: string;
  label: string;
  color: string;
  values: number[];
}

/** Zeichenfläche des Verlauf-Charts in px — die Breite kommt vom Platz auf der Seite (1:1 gezeichnet, nicht skaliert) */
const CHART = { height: 300, left: 48, right: 10, top: 12, bottom: 24 };
const BANK_COLOR = '#7f8c8d';
const SHOP_COLOR = '#0f766e';
const ARCADE_COLOR = '#c0392b';
const MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

/**
 * Farbe eines Manager-Kontos im Verlauf: ein gleichmäßiger Verlauf von kräftigem Gelb (unten, größtes Konto) zu
 * hellem Gelb (oben) — so bleiben die Konten zusammen als "Im Umlauf" erkennbar (Bank grau, Shop türkis heben sich
 * ab), und jede Fläche ist ein Stück heller als die darunter.
 */
function managerShade(index: number, count: number): string {
  const t = count > 1 ? index / (count - 1) : 0;
  return `hsl(${Math.round(42 + t * 10)} 92% ${Math.round(46 + t * 36)}%)`;
}

/** Gegenseite der Konten: wohin ausgegebene Lukaten gehen */
interface CounterpartRow {
  kind: 'bank' | 'shop' | 'arcade';
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
  priceLevel = computed(() => this.overview()?.price_level ?? null);

  factorLabel(f: number): string { return '×' + f.toLocaleString('de-DE', { maximumFractionDigits: 2 }); }
  levelRange(l: { from: number; to: number | null }): string {
    return l.to === null ? `ab ${this.format(l.from)}` : `${this.format(l.from)}–${this.format(l.to)}`;
  }
  dateLabel(iso: string): string { return `${iso.slice(8, 10)}.${iso.slice(5, 7)}.`; }
  totals = computed(() => this.overview()?.totals ?? null);

  /**
   * Bank = Gegenseite aller Tipps (Einsätze rein, Gewinne raus), Shop = für Sticker-Packs ausgegebene Lukaten,
   * Spielhalle = Einsätze minus Gewinne am Automaten. Zusammen mit "Im Umlauf" ergibt das alles, was je entstanden
   * ist (Startbonus + Einträge + Gekauft).
   */
  counterparts = computed<CounterpartRow[]>(() => {
    const t = this.totals();
    if (!t) return [];
    const stakes = t.stakes ?? 0, payouts = t.payouts ?? 0;
    return [
      { kind: 'bank', name: 'Bank', note: 'Gegenseite aller Tipps', received: stakes, paid: payouts, balance: stakes - payouts },
      { kind: 'shop', name: 'Shop', note: 'Sticker-Packs', received: -t.packs, paid: null, balance: -t.packs },
      { kind: 'arcade', name: 'Spielhalle', note: 'Einarmiger Bandit', received: t.slot_stakes ?? 0, paid: t.slot_payouts ?? 0, balance: -(t.slot ?? 0) },
    ];
  });

  constructor() {
    this.api.get<LukatenOverview>('lukaten/overview').subscribe({
      next: o => this.overview.set(o),
      error: () => this.overview.set(null),
    });
    this.api.get<LukatenHistory>('lukaten/history').subscribe({
      next: h => this.history.set(h.ready && h.days.length > 1 ? h : null),
      error: () => this.history.set(null),
    });
  }

  // ── Verlauf: Lukaten je Tag seit dem Stichtag ──
  /** undefined = lädt, null = nicht verfügbar */
  history = signal<LukatenHistory | null | undefined>(undefined);
  /** Bank, Shop und Spielhalle mit in den Stapel nehmen (was aus dem Umlauf abgeflossen ist) */
  showBank = signal(true);
  showShop = signal(true);
  showArcade = signal(true);
  /**
   * Total = Lukaten absolut gestapelt; Relativ = jeder Tag auf 100 % gestreckt — zeigt die Verteilung auch dann
   * deutlich, wenn der Umlauf wächst. Pro Gerät gemerkt.
   */
  readonly CHART_MODES = [
    { key: 'total' as const, label: 'Total' },
    { key: 'relative' as const, label: 'Relativ' },
  ];
  chartMode = signal<'total' | 'relative'>(this.loadChartMode());
  setChartMode(mode: 'total' | 'relative'): void {
    this.chartMode.set(mode);
    try { localStorage.setItem('lukaten-chart-mode', mode); } catch {}
  }
  private loadChartMode(): 'total' | 'relative' {
    try {
      return localStorage.getItem('lukaten-chart-mode') === 'relative' ? 'relative' : 'total';
    } catch {
      return 'total';
    }
  }

  /** Tag unter dem Mauszeiger (Index in days), null = letzter Tag */
  hoverIndex = signal<number | null>(null);

  /** Breite des Charts = Breite seines Platzes auf der Seite; so wird 1:1 gezeichnet und nichts unscharf skaliert */
  private chartWidth = signal(900);
  chart = computed(() => ({ ...CHART, width: this.chartWidth() }));
  private resize?: ResizeObserver;
  private destroyRef = inject(DestroyRef);

  @ViewChild('chartBox') set chartBox(el: ElementRef<HTMLElement> | undefined) {
    this.resize?.disconnect();
    if (!el) return;
    this.resize = new ResizeObserver(entries => {
      const width = Math.round(entries[0].contentRect.width);
      if (width > 0 && width !== this.chartWidth()) this.chartWidth.set(width);
    });
    this.resize.observe(el.nativeElement);
    this.destroyRef.onDestroy(() => this.resize?.disconnect());
  }

  /** Flächen von unten nach oben: Konten (bzw. ihre Summe), dann Bank, Shop, Spielhalle */
  chartSeries = computed<ChartSeries[]>(() => {
    const h = this.history();
    if (!h) return [];
    const series: ChartSeries[] = [];
    // jeder Manager eine eigene Fläche in einem Gelbton — zusammen sind sie "Im Umlauf"
    h.managers.forEach((m, i) => series.push({
      key: m.manager_id, label: m.manager_name, color: managerShade(i, h.managers.length), values: m.balance,
    }));
    if (this.showBank()) series.push({ key: 'bank', label: 'Bank', color: BANK_COLOR, values: h.bank });
    if (this.showShop()) series.push({ key: 'shop', label: 'Shop', color: SHOP_COLOR, values: h.shop });
    if (this.showArcade() && h.arcade) series.push({ key: 'arcade', label: 'Spielhalle', color: ARCADE_COLOR, values: h.arcade });
    return series;
  });

  /** Gestapelte Flächen als SVG-Pfade; negative Stände (Konto im Minus, Bank im Minus) zählen im Stapel als 0 */
  chartAreas = computed(() => {
    const h = this.history();
    const series = this.chartSeries();
    if (!h || !series.length) return { areas: [], ticks: [], months: [], max: 0 };
    const n = h.days.length;
    const base = new Array<number>(n).fill(0);
    const layers = series.map(s => {
      const lower = [...base];
      s.values.forEach((v, i) => { base[i] += Math.max(0, v); });
      return { series: s, lower, upper: [...base] };
    });
    // Relativ: jeden Tag auf 100 % strecken (Anteil am Stapel des Tages; ein leerer Tag bleibt leer)
    const relative = this.chartMode() === 'relative';
    if (relative) {
      for (const l of layers) {
        l.lower = l.lower.map((v, i) => base[i] > 0 ? v / base[i] * 100 : 0);
        l.upper = l.upper.map((v, i) => base[i] > 0 ? v / base[i] * 100 : 0);
      }
    }
    // Total: die nächste Schwelle der Preisstufen mit ins Bild nehmen, damit man sieht, wie weit sie noch weg ist
    const top = Math.max(1, ...base);
    const nextThreshold = (this.priceLevel()?.levels ?? []).map(l => l.from).find(f => f > top) ?? 0;
    const max = relative ? 100 : this.niceMax(Math.max(top, nextThreshold));
    const width = this.chartWidth();
    const x = (i: number) => CHART.left + (i / (n - 1)) * (width - CHART.left - CHART.right);
    const y = (v: number) => CHART.top + (1 - v / max) * (CHART.height - CHART.top - CHART.bottom);
    const areas = layers.map(l => ({
      key: l.series.key,
      color: l.series.color,
      path: 'M' + l.upper.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('L')
        + 'L' + l.lower.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).reverse().join('L') + 'Z',
    }));
    const ticks = [0, 0.25, 0.5, 0.75, 1].map(f => ({
      y: y(max * f),
      label: relative ? `${Math.round(f * 100)} %` : LukatenService.format(Math.round(max * f)),
    }));
    const months = h.days
      .map((day, i) => ({ day, i }))
      .filter(d => d.day.endsWith('-01'))
      .map(d => ({ x: x(d.i), label: MONTHS[+d.day.slice(5, 7) - 1] }));
    // Im Umlauf, Mittel der letzten 14 Tage (Grundlage der Preisstufe) + Schwellen der Stufen — nur bei Total
    const avg = this.circulationAvg();
    const avgPath = !relative && avg.length
      ? 'M' + avg.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('L')
      : null;
    const thresholds = relative ? [] : (this.priceLevel()?.levels ?? [])
      .map((l, i) => ({ y: y(l.from), from: l.from, label: this.factorLabel(l.factor), level: i }))
      .filter(t => t.from > 0 && t.from <= max);
    return { areas, ticks, months, max, x, avgPath, thresholds };
  });

  /** Lukaten im Umlauf (alle Konten) je Tag, gemittelt über die letzten 14 Tage — so wie die Preisstufe rechnet */
  circulationAvg = computed<number[]>(() => {
    const h = this.history();
    if (!h) return [];
    const days = this.priceLevel()?.days ?? 14;
    const circ = h.days.map((_, i) => h.managers.reduce((sum, m) => sum + m.balance[i], 0));
    return circ.map((_, i) => {
      const from = Math.max(0, i - days + 1);
      const slice = circ.slice(from, i + 1);
      return slice.reduce((a, b) => a + b, 0) / slice.length;
    });
  });

  /** Tag, dessen Werte die Legende zeigt: unter dem Mauszeiger, sonst der letzte */
  chartDay = computed(() => {
    const h = this.history();
    if (!h) return null;
    const i = Math.min(h.days.length - 1, Math.max(0, this.hoverIndex() ?? h.days.length - 1));
    const [year, month, day] = h.days[i].split('-');
    const series = this.chartSeries();
    // Anteil am Stapel des Tages (wie im Relativ-Modus gezeichnet: Stände im Minus zählen als 0)
    const stack = series.reduce((sum, s) => sum + Math.max(0, s.values[i]), 0);
    const share = (v: number) => stack > 0 ? `${Math.round(Math.max(0, v) / stack * 100)} %` : '0 %';
    const entry = (s: ChartSeries) => ({ key: s.key, label: s.label, color: s.color, value: s.values[i], share: share(s.values[i]) });
    return {
      index: i,
      label: `${day}.${month}.${year}`,
      x: this.chartAreas().x?.(i) ?? 0,
      // Tooltip links vom Zeiger, sobald er in der rechten Hälfte ist — sonst liefe er aus dem Chart
      flip: i > (h.days.length - 1) / 2,
      values: series.map(entry),
      // für den Tooltip von oben nach unten, wie die Flächen im Chart liegen (Spielhalle, Shop, Bank, dann die Konten)
      topDown: series.map(entry).reverse(),
      total: series.reduce((sum, s) => sum + s.values[i], 0),
      avg: this.circulationAvg()[i] ?? null,
    };
  });

  onChartMove(event: MouseEvent | TouchEvent, svg: Element): void {
    const h = this.history();
    if (!h) return;
    const rect = svg.getBoundingClientRect();
    const clientX = 'touches' in event ? event.touches[0]?.clientX ?? 0 : event.clientX;
    const ratio = (clientX - rect.left - CHART.left) / (rect.width - CHART.left - CHART.right);
    this.hoverIndex.set(Math.min(h.days.length - 1, Math.max(0, Math.round(ratio * (h.days.length - 1)))));
  }

  /** nächste "runde" Obergrenze für die Achse */
  private niceMax(v: number): number {
    const pow = Math.pow(10, Math.floor(Math.log10(v)));
    const step = [1, 2, 2.5, 4, 5, 10].find(f => f * pow >= v) ?? 10;
    return step * pow;
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
