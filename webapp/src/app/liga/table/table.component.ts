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

  rows           = computed(() => (this.state().data?.standings        ?? []) as any[]);
  lucky          = computed(() => (this.state().data?.luck?.lucky          ?? []) as any[]);
  unlucky        = computed(() => (this.state().data?.luck?.unlucky         ?? []) as any[]);
  goldeneBuerste = computed(() => (this.state().data?.luck?.goldene_buerste ?? []) as any[]);
  hoelzerneBand  = computed(() => (this.state().data?.luck?.hoelzerne_bank  ?? []) as any[]);
  loading        = computed(() => this.state().loading);
  error          = computed(() => this.state().error);

  logoErrors = new Set<string>();
  onLogoError(teamId: string) { this.logoErrors.add(teamId); }

  constructor() {
    this.cache.ensureSeasons();
  }
}
