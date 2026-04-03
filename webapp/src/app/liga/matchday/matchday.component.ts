import { Component, computed, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, filter, map, of, startWith, switchMap } from 'rxjs';
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

  selectedNumber = signal<number | null>(null);

  private ratingsState = toSignal(
    combineLatest([
      toObservable(this.activeSeason).pipe(filter(s => s !== null)),
      toObservable(this.selectedNumber),
    ]).pipe(
      switchMap(([season, number]) => {
        const url = number !== null
          ? `team_rating?season_id=${season!.id}&matchday_number=${number}`
          : `team_rating?season_id=${season!.id}`;
        return this.api.get<any>(url).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        );
      })
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  seasonId       = computed(() => this.activeSeason()?.id ?? null);
  matchday       = computed(() => this.ratingsState().data?.matchday ?? null);
  maxNumber      = computed(() => this.ratingsState().data?.max_matchday_number ?? 1);
  ratings        = computed(() => (this.ratingsState().data?.ratings ?? []) as any[]);
  sdsPlayer      = computed(() => this.ratingsState().data?.sds_player ?? null);
  loading        = computed(() => this.ratingsState().loading ?? true);
  error          = computed(() => this.ratingsState().error ?? null);
  totalPoints    = computed(() => this.ratings().reduce((sum: number, r: any) => sum + Number(r.points), 0));

  currentNumber  = computed(() => this.matchday()?.number ?? null);
  canDecrement   = computed(() => (this.currentNumber() ?? 1) > 1);
  canIncrement   = computed(() => (this.currentNumber() ?? 1) < this.maxNumber());

  decrement() {
    const n = this.currentNumber();
    if (n && n > 1) this.selectedNumber.set(n - 1);
  }

  increment() {
    const n = this.currentNumber();
    if (n && n < this.maxNumber()) this.selectedNumber.set(n + 1);
  }

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
