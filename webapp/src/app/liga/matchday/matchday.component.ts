import { Component, computed, inject } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-matchday',
  standalone: false,
  templateUrl: './matchday.component.html',
  styleUrl: './matchday.component.scss'
})
export class MatchdayComponent {
  private api = inject(ApiService);
  cache       = inject(DataCacheService);

  private activeSeason = computed(() => {
    const seasons = this.cache.seasons();
    if (!seasons.length) return null;
    return seasons.reduce((a, b) => a.start_date > b.start_date ? a : b);
  });

  private ratingsState = toSignal(
    toObservable(this.activeSeason).pipe(
      filter(season => season !== null),
      switchMap(season =>
        this.api.get<any>(`team_rating?season_id=${season!.id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  seasonId    = computed(() => this.activeSeason()?.id ?? null);
  matchday    = computed(() => this.ratingsState().data?.matchday ?? null);
  ratings     = computed(() => (this.ratingsState().data?.ratings ?? []) as any[]);
  sdsPlayer   = computed(() => this.ratingsState().data?.sds_player ?? null);
  loading     = computed(() => this.ratingsState().loading ?? true);
  error       = computed(() => this.ratingsState().error ?? null);
  totalPoints = computed(() => this.ratings().reduce((sum: number, r: any) => sum + Number(r.points), 0));

  logoErrors = new Set<string>();
  onLogoError(teamId: string) { this.logoErrors.add(teamId); }
  range(n: number): number[] { return Array.from({ length: n }, (_, i) => i); }
  gradeVar(grade: string | null): string {
    if (!grade) return 'var(--grade-unset)';
    return `var(--grade-${grade.replace('.', '')})`;
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
