import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { ApiService } from '../../core/api.service';

interface HeatmapManager {
  manager_id: string;
  manager_name: string;
  alias: string | null;
  days: Record<string, number>; // "YYYY-MM-DD" -> Sekunden
}

interface HeatmapResponse {
  days: number;
  managers: HeatmapManager[];
}

const EMPTY_RESPONSE: HeatmapResponse = { days: 7, managers: [] };

@Component({
  selector: 'app-session-heatmap',
  standalone: false,
  templateUrl: './session-heatmap.component.html',
  styleUrl: './session-heatmap.component.scss',
})
export class SessionHeatmapComponent {
  private api = inject(ApiService);

  private data = toSignal(
    this.api.get<HeatmapResponse>('session?days=7').pipe(
      catchError(() => of(EMPTY_RESPONSE)),
    ),
  );

  loading = computed(() => this.data() === undefined);

  // Letzte N Tage (älteste zuerst) als "YYYY-MM-DD", passend zu den Keys aus dem Backend.
  dayColumns = computed(() => {
    const n = this.data()?.days ?? 7;
    const cols: { key: string; label: string }[] = [];
    for (let i = n - 1; i >= 0; i--) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      cols.push({
        key: d.toISOString().slice(0, 10),
        label: d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' }),
      });
    }
    return cols;
  });

  managers = computed(() =>
    [...(this.data()?.managers ?? [])].sort((a, b) => a.manager_name.localeCompare(b.manager_name)),
  );

  seconds(m: HeatmapManager, dayKey: string): number {
    return m.days[dayKey] ?? 0;
  }

  formatDuration(seconds: number): string {
    if (seconds <= 0) return '–';
    const totalMinutes = Math.round(seconds / 60);
    const h = Math.floor(totalMinutes / 60);
    const min = totalMinutes % 60;
    if (h > 0) return min > 0 ? `${h}h ${min}min` : `${h}h`;
    return `${min}min`;
  }

  // Farbintensität: 0 = keine Nutzung, ab hier linear bis zu einem Cap (2h) auf volle Intensität
  private readonly CAP_SECONDS = 2 * 60 * 60;

  cellBackground(seconds: number): string {
    if (seconds <= 0) return 'transparent';
    const intensity = Math.min(1, seconds / this.CAP_SECONDS);
    return `color-mix(in srgb, var(--flat-emerald) ${Math.round(intensity * 80)}%, transparent)`;
  }
}
