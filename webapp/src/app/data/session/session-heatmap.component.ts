import { Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

type RangeKey = 'day' | 'month' | 'year';

interface HeatmapManager {
  manager_id: string;
  manager_name: string;
  alias: string | null;
  buckets: Record<string, number>; // Bucket-Schlüssel (siehe RANGE_CONFIG) -> Sekunden gesamt (geräteübergreifend dedupliziert)
  mobile_seconds: Record<string, number>; // dieselben Buckets, nur Intervalle mobile/tablet, separat dedupliziert
  desktop_seconds: Record<string, number>; // dieselben Buckets, nur Intervalle desktop/unbekannt, separat dedupliziert
}

interface HeatmapResponse {
  range: RangeKey;
  managers: HeatmapManager[];
}

interface BucketColumn {
  key: string;
  label: string;
}

// Farbverlauf nach Gerätemix: 100% Desktop = Tomato, 100% Mobile = Dodger Blue, direkt linear
// gemischt dazwischen (kein eigener Anker für 50/50 nötig — der Mix ergibt bei diesen beiden
// Randfarben einen unterscheidbaren Lavendel-Ton, kein trübes Grau wie bei Komplementärfarben).
type Rgb = readonly [number, number, number];
const DESKTOP_RGB: Rgb = [255, 99, 72];  // #ff6348 tomato
const MOBILE_RGB: Rgb  = [30, 144, 255]; // #1e90ff dodger blue

// Gradient von 1s (10% Deckkraft) bis 60min (100%) — linear interpoliert, darüber hinaus
// gedeckelt. Der hohe Startwert bei 1s sorgt dafür, dass "kurz online" sich klar von "gar nicht
// online" (0s, transparent) abhebt, statt in einer Farbskala fast unsichtbar zu sein.
const GRADIENT_MIN_SECONDS = 1;
const GRADIENT_MAX_SECONDS = 60 * 60;
const GRADIENT_MIN_OPACITY = 0.1;
const GRADIENT_MAX_OPACITY = 1;

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

  // Anteil mobile/tablet an der Gerätenutzung dieses Buckets. Nenner ist bewusst
  // mobile_seconds + desktop_seconds (Summe der pro Gerät unabhängig deduplizierten Zeiten), nicht
  // buckets (der geräteübergreifend deduplizierte Gesamtwert) — bei gleichzeitiger
  // Mehrgeräte-Nutzung wäre der Anteil sonst künstlich zu hoch (siehe Backend-Doc,
  // SessionTrait::getSessionHeatmap). 0.5 (neutral/Gelb) als Fallback, falls beide 0 sind (Farbe
  // wird dann ohnehin nie benutzt, da seconds() für diesen Bucket dann auch 0 ist).
  mobileFraction(m: HeatmapManager, bucketKey: string): number {
    const mobile = m.mobile_seconds[bucketKey] ?? 0;
    const desktop = m.desktop_seconds[bucketKey] ?? 0;
    const denom = mobile + desktop;
    if (denom <= 0) return 0.5;
    return Math.min(1, Math.max(0, mobile / denom));
  }

  formatDuration(seconds: number): string {
    if (seconds <= 0) return 'Keine Nutzung';
    const totalMinutes = Math.round(seconds / 60);
    const h = Math.floor(totalMinutes / 60);
    const min = totalMinutes % 60;
    if (h > 0) return min > 0 ? `${h}h ${min}min` : `${h}h`;
    return `${min}min`;
  }

  private lerpRgb(a: Rgb, b: Rgb, t: number): Rgb {
    return [
      Math.round(a[0] + (b[0] - a[0]) * t),
      Math.round(a[1] + (b[1] - a[1]) * t),
      Math.round(a[2] + (b[2] - a[2]) * t),
    ];
  }

  private hueForMobileFraction(fraction: number): Rgb {
    return this.lerpRgb(DESKTOP_RGB, MOBILE_RGB, fraction);
  }

  cellColor(seconds: number, mobileFraction: number): string {
    if (seconds <= 0) return 'transparent';
    const clamped = Math.min(Math.max(seconds, GRADIENT_MIN_SECONDS), GRADIENT_MAX_SECONDS);
    const t = (clamped - GRADIENT_MIN_SECONDS) / (GRADIENT_MAX_SECONDS - GRADIENT_MIN_SECONDS);
    const opacity = GRADIENT_MIN_OPACITY + t * (GRADIENT_MAX_OPACITY - GRADIENT_MIN_OPACITY);
    const [r, g, b] = this.hueForMobileFraction(mobileFraction);
    return `rgba(${r}, ${g}, ${b}, ${opacity.toFixed(2)})`;
  }

  private tooltipText(m: HeatmapManager, col: BucketColumn): string {
    const seconds = this.seconds(m, col.key);
    if (seconds <= 0) return `${col.key} — Keine Nutzung`;
    const mobilePct = Math.round(this.mobileFraction(m, col.key) * 100);
    return `${col.key} — ${this.formatDuration(seconds)} (${mobilePct}% mobil)`;
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
