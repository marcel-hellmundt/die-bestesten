import { Component, computed, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-liga-table',
  standalone: false,
  templateUrl: './table.component.html',
  styleUrl: './table.component.scss'
})
export class TableComponent {
  private api = inject(ApiService);
  cache       = inject(DataCacheService);

  // Seasons sorted newest first
  private seasons = computed(() =>
    [...this.cache.seasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))
  );

  selectedIndex = signal(0);

  selectedSeason = computed(() => this.seasons()[this.selectedIndex()] ?? null);

  canDecrement = computed(() => this.selectedIndex() < this.seasons().length - 1);
  canIncrement = computed(() => this.selectedIndex() > 0);

  decrement() { if (this.canDecrement()) this.selectedIndex.update(i => i + 1); }
  increment() { if (this.canIncrement()) this.selectedIndex.update(i => i - 1); }

  private state = toSignal(
    combineLatest([
      toObservable(this.seasons).pipe(filter(s => s.length > 0)),
      toObservable(this.selectedIndex),
    ]).pipe(
      switchMap(([seasons, idx]) => {
        const season = seasons[idx];
        if (!season) return of({ data: null, loading: false, error: null as string | null });
        return this.api.get<any>(`team_rating/season?season_id=${season.id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        );
      })
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  rows           = computed(() => (this.state().data?.standings           ?? []) as any[]);
  totalFines     = computed(() => this.rows().reduce((sum, r) => sum + Number(r.fine ?? 0), 0));
  lucky          = computed(() => (this.state().data?.luck?.lucky           ?? []) as any[]);
  unlucky        = computed(() => (this.state().data?.luck?.unlucky          ?? []) as any[]);
  goldeneBuerste = computed(() => (this.state().data?.luck?.goldene_buerste  ?? []) as any[]);
  hoelzerneBand  = computed(() => (this.state().data?.luck?.hoelzerne_bank   ?? []) as any[]);
  matchdayWins   = computed(() => (this.state().data?.luck?.matchday_wins    ?? []) as any[]);
  loading        = computed(() => this.state().loading);
  error          = computed(() => this.state().error);

  readonly chartW = 360;
  readonly chartH = 160;
  readonly padL   = 28;
  readonly padR   = 12;
  readonly padT   = 12;
  readonly padB   = 24;

  chartData = computed(() => {
    const series: any[] = this.state().data?.chart ?? [];
    if (!series.length) return null;

    const allPoints = series.flatMap((t: any) => t.series);
    if (allPoints.length < 2) return null;

    const maxMatchday = Math.max(...allPoints.map((s: any) => s.matchday));
    const maxPoints   = Math.max(...allPoints.map((s: any) => s.points));
    if (maxMatchday < 2 || maxPoints === 0) return null;

    const plotW = this.chartW - this.padL - this.padR;
    const plotH = this.chartH - this.padT - this.padB;

    const toX = (md: number) => this.padL + ((md - 1) / (maxMatchday - 1)) * plotW;
    const toY = (pts: number) => this.padT + plotH - (pts / maxPoints) * plotH;

    const teams = series.map((t: any) => ({
      team_id:   t.team_id,
      team_name: t.team_name,
      color:     t.color ?? '#888888',
      pathD: (t.series as any[])
        .map((s: any, i: number) => `${i === 0 ? 'M' : 'L'}${toX(s.matchday).toFixed(1)},${toY(s.points).toFixed(1)}`)
        .join(' '),
    }));

    const yTicks = [
      { y: toY(maxPoints), label: String(maxPoints) },
      { y: toY(0),         label: '0' },
    ];

    const xLabels = [
      { x: toX(1),           label: 'Sp. 1' },
      { x: toX(maxMatchday), label: `ST ${maxMatchday}` },
    ];

    return { teams, yTicks, xLabels };
  });

  logoErrors = new Set<string>();
  onLogoError(teamId: string) { this.logoErrors.add(teamId); }

  constructor() {
    this.cache.ensureSeasons();
  }
}
