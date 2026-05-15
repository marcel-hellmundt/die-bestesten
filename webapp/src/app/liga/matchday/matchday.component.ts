import { Component, computed, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, filter, map, of, startWith, switchMap } from 'rxjs';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { Matchday } from '../../core/models/matchday.model';

@Component({
  selector: 'app-matchday',
  standalone: false,
  templateUrl: './matchday.component.html',
  styleUrl: './matchday.component.scss'
})
export class MatchdayComponent {
  private api    = inject(ApiService);
  private auth   = inject(AuthService);
  private router = inject(Router);
  cache          = inject(DataCacheService);

  // Seasons sorted newest first for dropdown (future seasons excluded)
  seasons = computed(() =>
    [...this.cache.startedSeasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))
  );

  // Default: newest season
  private defaultSeasonId = computed(() => this.seasons()[0]?.id ?? null);

  selectedSeasonId = signal<string | null>(null);

  private effectiveSeasonId = computed(() =>
    this.selectedSeasonId() ?? this.defaultSeasonId()
  );

  selectedNumber = signal<number | null>(null);

  // Season + matchdays as a single atomic unit — only emits when HTTP completes,
  // preventing combineLatest from firing with a stale matchday list mid-transition.
  private seasonData = toSignal(
    toObservable(this.effectiveSeasonId).pipe(
      filter((id): id is string => !!id),
      switchMap(id =>
        this.api.get<any[]>(`matchday?season_id=${id}`).pipe(
          map(data => ({
            season: this.cache.seasons().find(s => s.id === id) ?? null,
            matchdays: data.map(Matchday.from) as Matchday[],
          })),
          catchError(() => of({
            season: this.cache.seasons().find(s => s.id === id) ?? null,
            matchdays: [] as Matchday[],
          }))
        )
      )
    )
  );

  matchdays = computed(() => this.seasonData()?.matchdays ?? []);

  private computeDefaultNumber(matchdays: Matchday[]): number | null {
    if (!matchdays.length) return null;
    const now = new Date();
    const sorted = [...matchdays].sort((a, b) => a.number - b.number);
    const uncompleted = sorted.find(m => !m.completed);
    if (!uncompleted) return sorted[sorted.length - 1].number;
    if (new Date(uncompleted.kickoff_date) <= now) return uncompleted.number;
    return uncompleted.number > 1 ? uncompleted.number - 1 : 1;
  }

  private ratingsState = toSignal(
    combineLatest([
      toObservable(this.seasonData).pipe(filter((sd): sd is NonNullable<typeof sd> => sd !== undefined)),
      toObservable(this.selectedNumber),
    ]).pipe(
      switchMap(([{ season, matchdays }, number]) => {
        // Validate number against the loaded matchdays; fall back to default if it doesn't exist.
        const validNumber = number !== null && matchdays.some(m => m.number === number) ? number : null;
        const effectiveNumber = validNumber ?? this.computeDefaultNumber(matchdays);
        const url = effectiveNumber !== null
          ? `team_rating?season_id=${season!.id}&matchday_number=${effectiveNumber}`
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

  seasonId       = computed(() => this.seasonData()?.season?.id ?? null);
  matchday       = computed(() => this.ratingsState().data?.matchday ?? null);
  maxNumber      = computed(() => this.ratingsState().data?.max_matchday_number ?? 1);
  ratings        = computed(() => (this.ratingsState().data?.ratings ?? []) as any[]);
  sdsPlayer      = computed(() => this.ratingsState().data?.sds_player ?? null);
  loading        = computed(() => this.ratingsState().loading ?? true);
  error          = computed(() => this.ratingsState().error ?? null);
  totalPoints    = computed(() => this.ratings().reduce((sum: number, r: any) => sum + Number(r.points), 0));
  totalFine      = computed(() => this.ratings().reduce((sum: number, r: any) => sum + Number(r.fine ?? 0), 0));

  mySeasonTeamId = computed(() =>
    (this.ratings().find((r: any) => r.manager_name === this.auth.getManagerName()) as any)?.team_id ?? null
  );

  isLive         = computed(() => this.matchday() && !this.matchday()?.completed);
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

  onSeasonChange(seasonId: string): void {
    this.selectedSeasonId.set(seasonId);
  }

  onMatchdayChange(number: number): void {
    this.selectedNumber.set(number);
  }

  navigateToTeam(teamId: string): void {
    this.router.navigate(['/team', teamId, 'aufstellung'], { queryParams: { matchday_id: this.matchday()?.id } });
  }

  bestXiMode = signal<'all' | 'free'>('all');

  private bestXiData = toSignal(
    combineLatest([
      toObservable(this.matchday).pipe(filter(m => !!m)),
      toObservable(this.bestXiMode),
    ]).pipe(
      switchMap(([md, mode]) => {
        const freeParam = mode === 'free' ? '&free_agents_only=1' : '';
        return this.api.get<any>(`player_rating/best_xi?matchday_id=${md!.id}${freeParam}`).pipe(
          catchError(() => of(null))
        );
      })
    )
  );

  bestXi = computed(() => this.bestXiData());

  formationLabel(f: string | number): string { return String(f).split('').join('-'); }

  bestXiRows = computed(() => {
    const xi = this.bestXi();
    if (!xi?.players) return [];
    const order = ['FORWARD', 'MIDFIELDER', 'DEFENDER', 'GOALKEEPER'];
    return order
      .map(pos => ({ pos, players: (xi.players as any[]).filter((p: any) => p.position === pos) }))
      .filter(row => row.players.length > 0);
  });

  logoErrors = new Set<string>();
  onLogoError(teamId: string) { this.logoErrors.add(teamId); }
  range(n: number): number[] { return Array.from({ length: n }, (_, i) => i); }
  gradeVar(grade: string | null): string {
    if (!grade) return 'var(--grade-unset)';
    return `var(--grade-${grade.replace('.', '')})`;
  }

  constructor() {
    this.cache.ensureSeasons();
    const n = inject(ActivatedRoute).snapshot.queryParamMap.get('number');
    if (n) this.selectedNumber.set(parseInt(n, 10));
  }
}
