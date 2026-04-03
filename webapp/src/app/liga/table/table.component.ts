import { Component, computed, inject } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, filter, map, of, startWith, switchMap } from 'rxjs';
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

  private activeSeason = computed(() => {
    const seasons = this.cache.seasons();
    if (!seasons.length) return null;
    return seasons.reduce((a, b) => a.start_date > b.start_date ? a : b);
  });

  private state = toSignal(
    toObservable(this.activeSeason).pipe(
      filter(s => s !== null),
      switchMap(s =>
        this.api.get<any>(`team_rating/season?season_id=${s!.id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  rows    = computed(() => (this.state().data?.standings ?? []) as any[]);
  lucky   = computed(() => (this.state().data?.luck?.lucky   ?? []) as any[]);
  unlucky = computed(() => (this.state().data?.luck?.unlucky ?? []) as any[]);
  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);

  logoErrors = new Set<string>();
  onLogoError(teamId: string) { this.logoErrors.add(teamId); }

  constructor() {
    this.cache.ensureSeasons();
  }
}
