import { Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

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

// Beschreibung des gesamten abgedeckten Zeitraums für den Manager-Zeilen-Tooltip (aggregiert über
// alle aktuell angezeigten Buckets) — Fenstergrößen wie in SessionTrait::getSessionHeatmap.
const RANGE_PERIOD_LABELS: Record<RangeKey, string> = {
  day: 'Letzte 24 Stunden', month: 'Letzte 30 Tage', year: 'Letzte 52 Wochen',
};

// Grobe Obergrenze für die Tooltip-Breite, nur zum Clampen der Position genutzt (siehe
// onCellHover) — muss nicht exakt sein, nur groß genug, damit der Tooltip nie über den
// Viewport-Rand hinausragt (das erzeugte vorher bei Zellen ganz rechts eine Scrollbar + Flackern,
// weil ein zentrierter CSS-::after-Tooltip nicht viewport-bewusst positioniert werden kann).
const TOOLTIP_WIDTH_ESTIMATE = 240;
const TOOLTIP_VIEWPORT_MARGIN = 8;

interface TooltipState {
  x: number;
  y: number;
  timeLabel: string;
  hasUsage: boolean;
  totalLabel: string;
  mobileLabel: string;
  mobilePct: number;
  desktopLabel: string;
  desktopPct: number;
}

@Component({
  selector: 'app-session-heatmap',
  standalone: false,
  templateUrl: './session-heatmap.component.html',
  styleUrl: './session-heatmap.component.scss',
})
export class SessionHeatmapComponent {
  private api = inject(ApiService);
  cache        = inject(DataCacheService);

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

  // Sortierung der Manager-Zeilen soll unabhängig vom gewählten Intervall (Tag/Monat/Jahr) immer
  // dieselbe Reihenfolge zeigen, statt bei jedem Range-Wechsel neu nach der Nutzung NUR dieses
  // Zeitraums zu sortieren. 'year' (letzte 51 Wochen) ist der breiteste verfügbare Zeitraum und
  // dient hier als globaler Referenzwert — einmalig geladen, unabhängig vom range-Signal.
  private globalTotals = toSignal(
    this.api.get<HeatmapResponse>('session?range=year').pipe(
      catchError(() => of({ range: 'year', managers: [] } as HeatmapResponse)),
    ),
  );

  private globalTotalSeconds = computed(() => {
    const map = new Map<string, number>();
    for (const m of this.globalTotals()?.managers ?? []) {
      map.set(m.manager_id, this.totalSeconds(m));
    }
    return map;
  });

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

  private sumValues(record: Record<string, number>): number {
    return Object.values(record).reduce((sum, s) => sum + s, 0);
  }

  private totalSeconds(m: HeatmapManager): number {
    return this.sumValues(m.buckets);
  }

  // ── Mobile/Desktop-Piechart ──────────────────────────────────────────────────
  // Reiner CSS-conic-gradient-Kreis statt SVG-Arc-Pfaden — bei nur 2 Segmenten deutlich simpler
  // (keine Trigonometrie für Bogen-Koordinaten nötig) und genauso abhängigkeitsfrei. Gleiche
  // Farben wie der Geräte-Farbverlauf der Heatmap-Zellen (DESKTOP_RGB/MOBILE_RGB), damit die
  // Farbsprache über die ganze Seite konsistent bleibt.
  readonly mobileColor  = `rgb(${MOBILE_RGB.join(', ')})`;
  readonly desktopColor = `rgb(${DESKTOP_RGB.join(', ')})`;

  // Summe Mobile-/Desktop-Sekunden über alle Manager, für den aktuell gewählten Zeitraum (gleicher
  // Scope wie usageChart darunter — nicht global wie globalTotalSeconds für die Zeilen-Sortierung).
  private deviceTotals = computed(() => {
    let mobile = 0, desktop = 0;
    for (const m of this.data()?.managers ?? []) {
      mobile  += this.sumValues(m.mobile_seconds);
      desktop += this.sumValues(m.desktop_seconds);
    }
    return { mobile, desktop };
  });

  // Gesamtnutzung (Mobil + Desktop) über alle Manager im aktuell gewählten Zeitraum — gleicher
  // Scope wie devicePie/deviceTotals, nur als einzelne formatierte Summe statt Kreisdiagramm.
  totalUsageLabel = computed(() => {
    const { mobile, desktop } = this.deviceTotals();
    return this.formatDuration(mobile + desktop);
  });

  devicePie = computed(() => {
    const { mobile, desktop } = this.deviceTotals();
    const total = mobile + desktop;
    if (total <= 0) return null;

    const mobilePct = Math.round(mobile / total * 100);
    return {
      mobilePct,
      desktopPct:   100 - mobilePct,
      mobileLabel:  this.formatDuration(mobile),
      desktopLabel: this.formatDuration(desktop),
      // CSS conic-gradient: erstes Segment 0%..mobilePct%, Rest Desktop — direkt als fertiger
      // background-Wert, damit das Template nur noch [style.background] binden muss.
      gradient: `conic-gradient(${this.mobileColor} 0% ${mobilePct}%, ${this.desktopColor} ${mobilePct}% 100%)`,
    };
  });

  // ── Poweruser-Karte ──────────────────────────────────────────────────────────
  // Manager mit der höchsten Nutzung im aktuell gewählten Zeitraum (gleicher Scope wie
  // devicePie/totalUsageLabel) — nicht zu verwechseln mit managers()' Sortierung, die absichtlich
  // an der globalen (Jahres-)Summe hängt, damit die Zeilenreihenfolge beim Range-Wechsel stabil bleibt.
  powerUser = computed(() => {
    const list = this.data()?.managers ?? [];
    if (list.length === 0) return null;
    const top = list.reduce((best, m) => this.totalSeconds(m) > this.totalSeconds(best) ? m : best, list[0]);
    if (this.totalSeconds(top) <= 0) return null;
    return {
      managerId:   top.manager_id,
      managerName: top.manager_name,
      timeLabel:   this.formatDuration(this.totalSeconds(top)),
    };
  });

  avatarFailed = new Set<string>();
  onAvatarError(managerId: string): void { this.avatarFailed.add(managerId); }

  // ── Gesamtnutzungs-Chart (Linechart über der Heatmap) ───────────────────────
  // Damit die Zeitspalten exakt über den Heatmap-Spalten liegen (nicht nur ungefähr, wie bei einem
  // eigenständigen Chart mit fixem Seitenverhältnis), ist das SVG kein eigenes Chart-Card, sondern
  // eine zusätzliche Zeile IM SELBEN .heatmap-Grid — per grid-column über exakt dieselben
  // Daten-Spalten-Tracks gelegt (siehe .usage-chart-svg in session-heatmap.component.scss). Das
  // ViewBox ist in Spalten-Einheiten (0..Spaltenzahl) mit preserveAspectRatio="none", wodurch
  // Spalte i im Chart deckungsgleich mit Heatmap-Spalte i skaliert — unabhängig von Fensterbreite
  // und Spaltenzahl (24/30/52).
  readonly chartColor = '#bf1d00'; // == $color-accent; kein Team-Kontext hier wie in team-overview
  private readonly USAGE_CHART_PAD = 12; // ViewBox-Einheiten Rand oben/unten (ViewBox-Höhe fix 100)

  // Summe der Sekunden aller Manager, je Bucket-Key — transponiert zu totalSeconds() (das pro
  // Manager über alle Buckets summiert statt pro Bucket über alle Manager).
  private totalsByBucket = computed(() => {
    const map = new Map<string, number>();
    for (const m of this.data()?.managers ?? []) {
      for (const [key, secs] of Object.entries(m.buckets)) {
        map.set(key, (map.get(key) ?? 0) + secs);
      }
    }
    return map;
  });

  // Mobile-/Desktop-Aufschlüsselung derselben Summe, je Bucket-Key — für den Linechart-Tooltip
  // (analog zu deviceTotals oben, aber pro Spalte statt über den gesamten Zeitraum aggregiert).
  private deviceTotalsByBucket = computed(() => {
    const map = new Map<string, { mobile: number; desktop: number }>();
    for (const m of this.data()?.managers ?? []) {
      for (const [key, secs] of Object.entries(m.mobile_seconds)) {
        const entry = map.get(key) ?? { mobile: 0, desktop: 0 };
        entry.mobile += secs;
        map.set(key, entry);
      }
      for (const [key, secs] of Object.entries(m.desktop_seconds)) {
        const entry = map.get(key) ?? { mobile: 0, desktop: 0 };
        entry.desktop += secs;
        map.set(key, entry);
      }
    }
    return map;
  });

  usageChart = computed(() => {
    const cols = this.columns();
    if (cols.length === 0) return null;

    const totals   = this.totalsByBucket();
    const values   = cols.map(c => totals.get(c.key) ?? 0);
    const maxValue = Math.max(...values, 1);

    const top    = this.USAGE_CHART_PAD;
    const bottom = 100 - this.USAGE_CHART_PAD;
    const h      = bottom - top;

    // x in Spalten-Einheiten (Mitte von Spalte i = i + 0.5) — passend zum ViewBox "0 0 N 100".
    const dots = values.map((v, i) => ({ x: i + 0.5, y: bottom - (v / maxValue) * h }));
    const line = dots.map((d, i) => `${i === 0 ? 'M' : 'L'}${d.x.toFixed(3)},${d.y.toFixed(1)}`).join(' ');

    return { line, columnCount: cols.length };
  });

  // Absteigend nach globaler Gesamtnutzung (siehe globalTotalSeconds — unabhängig vom gewählten
  // Intervall, damit die Reihenfolge beim Wechsel zwischen Tag/Monat/Jahr stabil bleibt), bei
  // Gleichstand alphabetisch als stabiler Tiebreaker.
  managers = computed(() => {
    const totals = this.globalTotalSeconds();
    return [...(this.data()?.managers ?? [])].sort((a, b) =>
      (totals.get(b.manager_id) ?? 0) - (totals.get(a.manager_id) ?? 0)
        || a.manager_name.localeCompare(b.manager_name),
    );
  });

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

  // Formatierte Zeitangabe für den Tooltip-Titel — abhängig vom gewählten Intervall, da die
  // Bucket-Keys je nach range unterschiedlich aufgebaut sind (siehe SessionTrait::getSessionHeatmap):
  // 'day' → "YYYY-MM-DDTHH:00:00" (Stunde), 'month'/'year' → "YYYY-MM-DD" (year: Montag der Woche).
  // Datumsangaben ohne Uhrzeit werden explizit mit "T00:00:00" geparst, da new Date("YYYY-MM-DD")
  // sonst als UTC-Mitternacht statt Lokalzeit interpretiert wird (JS-Falle) und je nach Zeitzone
  // auf den Vortag verschieben könnte.
  private formatBucketLabel(col: BucketColumn): string {
    if (this.range() === 'day') {
      const d = new Date(col.key);
      const day = d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
      return `${day}, ${String(d.getHours()).padStart(2, '0')}:00 Uhr`;
    }
    const d = new Date(`${col.key}T00:00:00`);
    const dateLabel = d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
    return this.range() === 'year' ? `Woche ab ${dateLabel}` : dateLabel;
  }

  private formatDurationOrZero(seconds: number): string {
    return seconds > 0 ? this.formatDuration(seconds) : '0min';
  }

  hoveredTooltip = signal<TooltipState | null>(null);

  private tooltipPosition(event: MouseEvent): { x: number; y: number } {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const half = TOOLTIP_WIDTH_ESTIMATE / 2;
    const x = Math.min(
      window.innerWidth - half - TOOLTIP_VIEWPORT_MARGIN,
      Math.max(half + TOOLTIP_VIEWPORT_MARGIN, rect.left + rect.width / 2),
    );
    return { x, y: rect.top };
  }

  private buildTooltip(
    timeLabel: string, total: number, mobile: number, desktop: number, pos: { x: number; y: number },
  ): TooltipState {
    const deviceSum = mobile + desktop;
    return {
      ...pos,
      timeLabel,
      hasUsage:     total > 0,
      totalLabel:   this.formatDuration(total),
      mobileLabel:  this.formatDurationOrZero(mobile),
      mobilePct:    deviceSum > 0 ? Math.round(mobile / deviceSum * 100) : 0,
      desktopLabel: this.formatDurationOrZero(desktop),
      desktopPct:   deviceSum > 0 ? Math.round(desktop / deviceSum * 100) : 0,
    };
  }

  onCellHover(event: MouseEvent, m: HeatmapManager, col: BucketColumn): void {
    const total   = this.seconds(m, col.key);
    const mobile  = m.mobile_seconds[col.key] ?? 0;
    const desktop = m.desktop_seconds[col.key] ?? 0;
    this.hoveredTooltip.set(
      this.buildTooltip(this.formatBucketLabel(col), total, mobile, desktop, this.tooltipPosition(event)),
    );
  }

  // Gleicher Tooltip wie onCellHover, aber aggregiert über alle aktuell angezeigten Buckets dieses
  // Managers (== die komplette Zeile) statt nur einer einzelnen Zelle.
  onRowLabelHover(event: MouseEvent, m: HeatmapManager): void {
    const total   = this.totalSeconds(m);
    const mobile  = this.sumValues(m.mobile_seconds);
    const desktop = this.sumValues(m.desktop_seconds);
    this.hoveredTooltip.set(
      this.buildTooltip(RANGE_PERIOD_LABELS[this.range()], total, mobile, desktop, this.tooltipPosition(event)),
    );
  }

  // Gleicher Tooltip wie onCellHover, aber aggregiert über alle Manager dieses Zeitslots (== eine
  // Spalte im Linechart) statt einer einzelnen Manager-Zelle.
  onChartColumnHover(event: MouseEvent, col: BucketColumn): void {
    const total  = this.totalsByBucket().get(col.key) ?? 0;
    const device = this.deviceTotalsByBucket().get(col.key);
    this.hoveredTooltip.set(
      this.buildTooltip(this.formatBucketLabel(col), total, device?.mobile ?? 0, device?.desktop ?? 0, this.tooltipPosition(event)),
    );
  }

  onCellLeave(): void {
    this.hoveredTooltip.set(null);
  }
}
