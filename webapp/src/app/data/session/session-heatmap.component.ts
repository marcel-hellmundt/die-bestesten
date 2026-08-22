import { Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

type RangeKey = 'day' | 'month' | 'year';

interface HeatmapManager {
  manager_id: string;
  manager_name: string;
  alias: string | null;
  buckets: Record<string, number>; // Bucket-Schlüssel (siehe RANGE_CONFIG) -> Sekunden
}

interface HeatmapResponse {
  range: RangeKey;
  managers: HeatmapManager[];
}

interface BucketColumn {
  key: string;
  label: string;
}

// Sequentielle Ein-Hue-Rampe (hell→dunkel = wenig→viel Nutzung), 4 feste Stufen statt eines
// stetigen Verlaufs — leichter zu unterscheiden als ein kontinuierlicher Gradient.
const LEVEL_COLORS = ['#cde2fb', '#86b6ef', '#3987e5', '#184f95'] as const;

// Absolute Sekunden-Schwellen je Zeitraum — an die maximal mögliche Bucket-Dauer angepasst
// (eine Stunde kann max. 3600s enthalten, eine Wochen-Bucket im Jahresblick bis zu 7×86400s),
// damit die Farbskala in jeder Ansicht tatsächlich ausgenutzt wird statt immer nur die
// hellste Stufe zu zeigen.
const RANGE_THRESHOLDS: Record<RangeKey, readonly [number, number, number]> = {
  day:   [5 * 60, 20 * 60, 40 * 60],
  month: [15 * 60, 60 * 60, 3 * 60 * 60],
  year:  [60 * 60, 4 * 60 * 60, 12 * 60 * 60],
};

const RANGE_LABELS: Record<RangeKey, string> = {
  day: 'Tag', month: 'Monat', year: 'Jahr',
};

// Grobe Obergrenze für die Tooltip-Breite, nur zum Clampen der Position genutzt (siehe
// onCellHover) — muss nicht exakt sein, nur groß genug, damit der Tooltip nie über den
// Viewport-Rand hinausragt (das erzeugte vorher bei Zellen ganz rechts eine Scrollbar + Flackern,
// weil ein zentrierter CSS-::after-Tooltip nicht viewport-bewusst positioniert werden kann).
const TOOLTIP_WIDTH_ESTIMATE = 240;
const TOOLTIP_VIEWPORT_MARGIN = 8;

interface TooltipState {
  text: string;
  x: number;
  y: number;
}

@Component({
  selector: 'app-session-heatmap',
  standalone: false,
  templateUrl: './session-heatmap.component.html',
  styleUrl: './session-heatmap.component.scss',
})
export class SessionHeatmapComponent {
  private api = inject(ApiService);

  readonly RANGES: RangeKey[] = ['day', 'month', 'year'];
  readonly rangeLabels = RANGE_LABELS;

  range = signal<RangeKey>('day');
  setRange(r: RangeKey): void { this.range.set(r); }

  private data = toSignal(
    toObservable(this.range).pipe(
      switchMap(range =>
        this.api.get<HeatmapResponse>(`session?range=${range}`).pipe(
          catchError(() => of({ range, managers: [] } as HeatmapResponse)),
        ),
      ),
    ),
  );

  loading = computed(() => this.data() === undefined);

  private localDateKey(d: Date): string {
    const y  = d.getFullYear();
    const mo = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${mo}-${day}`;
  }

  private hourKey(d: Date): string {
    const h = String(d.getHours()).padStart(2, '0');
    return `${this.localDateKey(d)}T${h}:00:00`;
  }

  private mondayOf(d: Date): Date {
    const offset = (d.getDay() + 6) % 7; // 0=Montag..6=Sonntag
    const monday = new Date(d);
    monday.setHours(0, 0, 0, 0);
    monday.setDate(d.getDate() - offset);
    return monday;
  }

  // Erwartete Bucket-Spalten für den aktuell gewählten Zeitraum — erzeugt clientseitig exakt
  // die Schlüssel, die das Backend liefert (siehe SessionTrait::getSessionHeatmap), damit auch
  // Buckets ohne jede Nutzung als leere Spalte erscheinen.
  columns = computed<BucketColumn[]>(() => {
    const range = this.range();
    const now = new Date();
    const cols: BucketColumn[] = [];

    if (range === 'day') {
      const currentHour = new Date(now);
      currentHour.setMinutes(0, 0, 0);
      for (let i = 23; i >= 0; i--) {
        const d = new Date(currentHour.getTime() - i * 60 * 60 * 1000);
        cols.push({ key: this.hourKey(d), label: `${String(d.getHours()).padStart(2, '0')}h` });
      }
    } else if (range === 'month') {
      for (let i = 29; i >= 0; i--) {
        const d = new Date(now);
        d.setDate(now.getDate() - i);
        const label = d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
        cols.push({ key: this.localDateKey(d), label });
      }
    } else {
      const thisMonday = this.mondayOf(now);
      let lastMonth = -1;
      for (let i = 51; i >= 0; i--) {
        const d = new Date(thisMonday.getTime() - i * 7 * 24 * 60 * 60 * 1000);
        const showLabel = d.getMonth() !== lastMonth;
        lastMonth = d.getMonth();
        cols.push({
          key: this.localDateKey(d),
          label: showLabel ? d.toLocaleDateString('de-DE', { month: 'short' }) : '',
        });
      }
    }

    return cols;
  });

  private totalSeconds(m: HeatmapManager): number {
    return Object.values(m.buckets).reduce((sum, s) => sum + s, 0);
  }

  // Absteigend nach Gesamtnutzung im Zeitraum, bei Gleichstand alphabetisch als stabiler Tiebreaker.
  managers = computed(() =>
    [...(this.data()?.managers ?? [])].sort((a, b) =>
      this.totalSeconds(b) - this.totalSeconds(a) || a.manager_name.localeCompare(b.manager_name),
    ),
  );

  seconds(m: HeatmapManager, bucketKey: string): number {
    return m.buckets[bucketKey] ?? 0;
  }

  formatDuration(seconds: number): string {
    if (seconds <= 0) return 'Keine Nutzung';
    const totalMinutes = Math.round(seconds / 60);
    const h = Math.floor(totalMinutes / 60);
    const min = totalMinutes % 60;
    if (h > 0) return min > 0 ? `${h}h ${min}min` : `${h}h`;
    return `${min}min`;
  }

  cellColor(seconds: number): string {
    if (seconds <= 0) return 'transparent';
    const thresholds = RANGE_THRESHOLDS[this.range()];
    const level = thresholds.filter(t => seconds >= t).length; // 0..3
    return LEVEL_COLORS[level];
  }

  private tooltipText(m: HeatmapManager, col: BucketColumn): string {
    return `${col.key} — ${this.formatDuration(this.seconds(m, col.key))}`;
  }

  hoveredTooltip = signal<TooltipState | null>(null);

  onCellHover(event: MouseEvent, m: HeatmapManager, col: BucketColumn): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const half = TOOLTIP_WIDTH_ESTIMATE / 2;
    const x = Math.min(
      window.innerWidth - half - TOOLTIP_VIEWPORT_MARGIN,
      Math.max(half + TOOLTIP_VIEWPORT_MARGIN, rect.left + rect.width / 2),
    );
    this.hoveredTooltip.set({ text: this.tooltipText(m, col), x, y: rect.top });
  }

  onCellLeave(): void {
    this.hoveredTooltip.set(null);
  }
}
