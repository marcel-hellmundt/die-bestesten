import { Component, DestroyRef, ElementRef, ViewChild, computed, inject, input, signal } from '@angular/core';
import { UniqueCurve } from '../sticker-sim';

/** Response von GET /sticker/history: je Manager, an welchem Tag wie viele Sticker erstmals dazukamen */
export interface StickerHistory {
  season_id: string | null;
  total: number;
  today: string;
  managers: {
    manager_id: string;
    manager_name: string;
    days: { date: string; pack: number; shop: number; trade: number }[];
  }[];
}

/** Simulierter Verlauf eines Manager-Profils */
export interface ProfileCurve { key: string; label: string; curve: UniqueCurve }

/** Echter Verlauf eines Managers, je Tag kumuliert */
interface RealCurve {
  key: string;
  label: string;
  unique: number[];   // verschiedene Sticker am Ende des Tages
  shop: number[];     // davon zuerst aus einem gekauften Pack
  trade: number[];    // davon zuerst eingetauscht
}

const CHART = { height: 280, left: 40, right: 12, top: 12, bottom: 24 };
const DAY_MS = 24 * 60 * 60 * 1000;
const MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
/** Farbe je Profil (folgt dem Profil, nicht der Reihenfolge) — CSS-Variablen, im Dark Mode eigene Stufen */
const PROFILE_SLOT: Record<string, number> = { active: 1, average: 2, inactive: 3 };

/**
 * Verschiedene Sticker im Lauf der Saison: simulierte Profile (Median + 10–90-%-Band, ohne Tauschen) gegen die echten
 * Sammlungen (GET /sticker/history) — zeigt, ob Tauschen und Kaufen die Alben schneller füllen als erwartet.
 * Beide in Prozent des jeweiligen Albums (Simulation: live berechnetes Album, echt: eingefrorenes).
 */
@Component({
  selector: 'app-unique-chart',
  standalone: false,
  templateUrl: './unique-chart.component.html',
  styleUrl: './unique-chart.component.scss',
})
export class UniqueChartComponent {
  /** Tage der Saison (0 = Stichtag) */
  days = input.required<number>();
  /** Datum von Tag 0 */
  start = input<Date | null>(null);
  profiles = input<ProfileCurve[] | null>(null);
  runs = input(0);
  history = input<StickerHistory | null | undefined>(undefined);

  showManagers = signal(true);
  /** Tag unter dem Zeiger; null = heute (bzw. Saisonende) */
  hoverDay = signal<number | null>(null);

  private width = signal(800);
  chart = computed(() => ({ ...CHART, width: this.width() }));
  private resize?: ResizeObserver;
  private destroyRef = inject(DestroyRef);

  @ViewChild('box') set box(el: ElementRef<HTMLElement> | undefined) {
    this.resize?.disconnect();
    if (!el) return;
    this.resize = new ResizeObserver(entries => {
      const w = Math.round(entries[0].contentRect.width);
      if (w > 0 && w !== this.width()) this.width.set(w);
    });
    this.resize.observe(el.nativeElement);
    this.destroyRef.onDestroy(() => this.resize?.disconnect());
  }

  slot(key: string, index: number): number { return PROFILE_SLOT[key] ?? (index % 3) + 1; }

  private dayOf(date: string): number | null {
    const start = this.start();
    if (!start) return null;
    return Math.round((new Date(date + 'T00:00:00').getTime() - start.getTime()) / DAY_MS);
  }

  /** Letzter Tag mit echten Daten (heute), auf die Saison begrenzt; null = Saison noch nicht begonnen */
  today = computed(() => {
    const h = this.history();
    const d = h ? this.dayOf(h.today) : null;
    return d === null || d < 0 ? null : Math.min(d, this.days());
  });

  realCurves = computed<RealCurve[]>(() => {
    const h = this.history(), today = this.today();
    if (!h || today === null) return [];
    return h.managers.map(m => {
      const add = (field: 'all' | 'shop' | 'trade') => {
        const perDay = new Array<number>(today + 1).fill(0);
        for (const d of m.days) {
          const i = Math.max(0, this.dayOf(d.date) ?? 0); // vor dem Stichtag gesammelt → Tag 0
          if (i > today) continue;
          perDay[i] += field === 'all' ? d.pack + d.shop + d.trade : d[field];
        }
        for (let i = 1; i < perDay.length; i++) perDay[i] += perDay[i - 1];
        return perDay;
      };
      return { key: m.manager_id, label: m.manager_name, unique: add('all'), shop: add('shop'), trade: add('trade') };
    });
  });

  /** Median der echten Manager je Tag (Anteil am Album) */
  realMedian = computed<number[]>(() => {
    const curves = this.realCurves(), total = this.history()?.total ?? 0, today = this.today();
    if (!curves.length || !total || today === null) return [];
    return Array.from({ length: today + 1 }, (_, d) => {
      const v = curves.map(c => c.unique[d] / total).sort((a, b) => a - b);
      const mid = Math.floor(v.length / 2);
      return v.length % 2 ? v[mid] : (v[mid - 1] + v[mid]) / 2;
    });
  });

  private x = computed(() => {
    const w = this.width(), days = Math.max(1, this.days());
    return (d: number) => CHART.left + (d / days) * (w - CHART.left - CHART.right);
  });
  private y = (f: number) => CHART.top + (1 - f) * (CHART.height - CHART.top - CHART.bottom);

  private line(values: number[]): string {
    const x = this.x();
    return values.map((v, d) => `${d ? 'L' : 'M'}${x(d).toFixed(1)},${this.y(v).toFixed(1)}`).join('');
  }

  geometry = computed(() => {
    const x = this.x(), total = this.history()?.total ?? 0, start = this.start(), days = this.days();
    const profiles = (this.profiles() ?? []).map((p, i) => ({
      key: p.key,
      slot: this.slot(p.key, i),
      median: this.line(p.curve.p50),
      band: this.line(p.curve.p90) + 'L'
        + p.curve.p10.map((v, d) => `${x(d).toFixed(1)},${this.y(v).toFixed(1)}`).reverse().join('L') + 'Z',
    }));
    const managers = total ? this.realCurves().map(c => ({ key: c.key, path: this.line(c.unique.map(u => u / total)) })) : [];
    const months: { x: number; label: string }[] = [];
    if (start) {
      for (let d = 0; d <= days; d++) {
        const date = new Date(start.getTime() + d * DAY_MS);
        if (date.getDate() === 1) months.push({ x: x(d), label: MONTHS[date.getMonth()] });
      }
    }
    const today = this.today();
    return {
      profiles,
      managers,
      median: this.realMedian().length ? this.line(this.realMedian()) : null,
      ticks: [0, 0.25, 0.5, 0.75, 1].map(f => ({ y: this.y(f), label: `${f * 100} %` })),
      months,
      today: today !== null && today < days ? x(today) : null,
    };
  });

  /** Werte des gezeigten Tags: unter dem Zeiger, sonst heute */
  readout = computed(() => {
    const days = this.days(), today = this.today();
    const d = this.hoverDay() ?? today ?? days;
    const start = this.start();
    const date = start ? new Date(start.getTime() + d * DAY_MS) : null;
    const total = this.history()?.total ?? 0;
    const real = today !== null && d <= today && total
      ? this.realCurves()
          .map(c => ({ key: c.key, label: c.label, unique: c.unique[d], share: c.unique[d] / total, shop: c.shop[d], trade: c.trade[d] }))
          .sort((a, b) => b.unique - a.unique)
      : null;
    return {
      day: d,
      x: this.x()(d),
      label: date ? date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : `Tag ${d}`,
      isToday: this.hoverDay() === null && today !== null,
      profiles: (this.profiles() ?? []).map((p, i) => ({
        key: p.key, label: p.label, slot: this.slot(p.key, i),
        p50: p.curve.p50[d] ?? 0, p10: p.curve.p10[d] ?? 0, p90: p.curve.p90[d] ?? 0,
      })),
      median: real ? this.realMedian()[d] : null,
      real,
      total,
    };
  });

  onMove(event: MouseEvent | TouchEvent, svg: Element): void {
    const rect = svg.getBoundingClientRect();
    const clientX = 'touches' in event ? event.touches[0]?.clientX ?? 0 : event.clientX;
    const ratio = (clientX - rect.left - CHART.left) / (rect.width - CHART.left - CHART.right);
    this.hoverDay.set(Math.min(this.days(), Math.max(0, Math.round(ratio * this.days()))));
  }
}
