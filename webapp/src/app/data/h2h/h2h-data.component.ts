import { Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

const PHASE_LABELS: Record<string, string> = {
  quarterfinal: 'Viertelfinale',
  semifinal:    'Halbfinale',
  final:        'Finale',
};

@Component({
  selector: 'app-h2h-data',
  standalone: false,
  templateUrl: './h2h-data.component.html',
  styleUrl: './h2h-data.component.scss',
})
export class H2HDataComponent {
  private api = inject(ApiService);
  cache       = inject(DataCacheService);

  private leagueState = toSignal(
    this.api.get<any[]>('league').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: [] as any[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as any[], loading: false, error: 'Fehler beim Laden' })),
    )
  );

  leagues = computed(() => this.leagueState()?.data ?? []);
  loading = computed(() => this.leagueState()?.loading ?? true);
  error   = computed(() => this.leagueState()?.error ?? null);

  activeSeasonId = computed(() => {
    const seasons = [...this.cache.startedSeasons()]
      .sort((a, b) => b.start_date.localeCompare(a.start_date));
    return seasons[0]?.id ?? null;
  });

  activeSeasonLabel = computed(() => {
    const seasons = [...this.cache.startedSeasons()]
      .sort((a, b) => b.start_date.localeCompare(a.start_date));
    const year = seasons[0]?.start_date?.slice(0, 4);
    if (!year) return '';
    return `${year}/${String(+year + 1).slice(-2)}`;
  });

  generateStates   = signal<Record<string, 'idle' | 'loading' | 'success' | 'error'>>({});
  generateMessages = signal<Record<string, string>>({});

  drawQFStates   = signal<Record<string, 'idle' | 'loading' | 'success' | 'error'>>({});
  drawQFMessages = signal<Record<string, string>>({});

  generateState(leagueId: string): 'idle' | 'loading' | 'success' | 'error' {
    return this.generateStates()[leagueId] ?? 'idle';
  }

  generateMessage(leagueId: string): string {
    return this.generateMessages()[leagueId] ?? '';
  }

  generate(league: any): void {
    const seasonId = this.activeSeasonId();
    if (!seasonId) return;

    this.generateStates.update(s => ({ ...s, [league.id]: 'loading' }));
    this.generateMessages.update(s => ({ ...s, [league.id]: '' }));

    this.api.post<any>('h2h/generate', { league_id: league.id, season_id: seasonId }).subscribe({
      next: res => {
        this.generateStates.update(s => ({ ...s, [league.id]: 'success' }));
        this.generateMessages.update(s => ({
          ...s,
          [league.id]: `${res.groups} Gruppen, ${res.matches} Matches erstellt`,
        }));
      },
      error: err => {
        this.generateStates.update(s => ({ ...s, [league.id]: 'error' }));
        const msg = err?.error?.message ?? 'Fehler beim Generieren';
        this.generateMessages.update(s => ({ ...s, [league.id]: msg }));
      },
    });
  }

  drawQFState(leagueId: string): 'idle' | 'loading' | 'success' | 'error' {
    return this.drawQFStates()[leagueId] ?? 'idle';
  }

  drawQFMessage(leagueId: string): string {
    return this.drawQFMessages()[leagueId] ?? '';
  }

  matchday18Completed = computed(() =>
    this.matchdays().find((md: any) => md.number === 18)?.completed ?? false
  );

  drawQuarterfinals(league: any): void {
    const seasonId = this.activeSeasonId();
    if (!seasonId) return;
    this.drawQFStates.update(s => ({ ...s, [league.id]: 'loading' }));
    this.drawQFMessages.update(s => ({ ...s, [league.id]: '' }));
    this.api.post<any>('h2h/draw_quarterfinals', { league_id: league.id, season_id: seasonId }).subscribe({
      next: res => {
        this.drawQFStates.update(s => ({ ...s, [league.id]: 'success' }));
        this.drawQFMessages.update(s => ({ ...s, [league.id]: `${res.matches} Matches angelegt` }));
        this.koReload$.next();
      },
      error: err => {
        this.drawQFStates.update(s => ({ ...s, [league.id]: 'error' }));
        this.drawQFMessages.update(s => ({ ...s, [league.id]: err?.error?.message ?? 'Fehler' }));
      },
    });
  }

  // ── KO-Match form ────────────────────────────────────────────────────────────

  readonly koPhases = [
    { value: 'quarterfinal', label: 'Viertelfinale' },
    { value: 'semifinal',    label: 'Halbfinale' },
    { value: 'final',        label: 'Finale' },
  ];

  readonly phaseLabel = PHASE_LABELS;

  private koReload$ = new BehaviorSubject<void>(undefined);

  private seasonId$ = toObservable(this.activeSeasonId);

  private teamState = toSignal(
    this.seasonId$.pipe(
      switchMap(id => id
        ? this.api.get<any[]>(`team?season_id=${id}`).pipe(
            catchError(() => of([] as any[]))
          )
        : of([] as any[])
      )
    )
  );

  private matchdayState = toSignal(
    this.seasonId$.pipe(
      switchMap(id => id
        ? this.api.get<any[]>(`matchday?season_id=${id}`).pipe(
            map(mds => [...mds].sort((a, b) => a.number - b.number)),
            catchError(() => of([] as any[]))
          )
        : of([] as any[])
      )
    )
  );

  private koState = toSignal(
    this.koReload$.pipe(
      switchMap(() => this.seasonId$.pipe(
        switchMap(id => id
          ? this.api.get<any>(`h2h?season_id=${id}`).pipe(
              map(data => data?.knockout_matches ?? []),
              catchError(() => of([] as any[]))
            )
          : of([] as any[])
        )
      ))
    )
  );

  teams      = computed(() => this.teamState()    ?? []);
  matchdays  = computed(() => this.matchdayState() ?? []);
  koMatches  = computed(() => {
    const matches = this.koState() ?? [];
    const order = ['quarterfinal', 'semifinal', 'final'];
    return [...matches].sort((a, b) => {
      const pi = order.indexOf(a.phase) - order.indexOf(b.phase);
      return pi !== 0 ? pi : a.sort_index - b.sort_index;
    });
  });

  koPhase      = signal('quarterfinal');
  koLeg        = signal(1);
  koHomeTeamId = signal('');
  koAwayTeamId = signal('');
  koMatchdayId = signal('');
  koSortIndex  = signal(0);

  koSubmitState = signal<'idle' | 'loading' | 'error'>('idle');
  koSubmitError = signal('');

  submitKoMatch(): void {
    const seasonId   = this.activeSeasonId();
    const phase      = this.koPhase();
    const leg        = phase === 'final' ? 1 : this.koLeg();
    const homeTeamId = this.koHomeTeamId();
    const awayTeamId = this.koAwayTeamId();
    const matchdayId = this.koMatchdayId();
    if (!seasonId || !homeTeamId || !awayTeamId || !matchdayId) return;

    this.koSubmitState.set('loading');
    this.api.post<any>('h2h', {
      season_id:    seasonId,
      phase,
      leg,
      home_team_id: homeTeamId,
      away_team_id: awayTeamId,
      matchday_id:  matchdayId,
      sort_index:   this.koSortIndex(),
    }).subscribe({
      next: () => {
        this.koSubmitState.set('idle');
        this.koHomeTeamId.set('');
        this.koAwayTeamId.set('');
        this.koMatchdayId.set('');
        this.koSortIndex.set(0);
        this.koReload$.next();
      },
      error: err => {
        this.koSubmitState.set('error');
        this.koSubmitError.set(err?.error?.message ?? 'Fehler beim Anlegen');
      },
    });
  }

  deleteStates = signal<Record<string, boolean>>({});

  deleteKoMatch(matchId: string): void {
    if (this.deleteStates()[matchId]) return;
    this.deleteStates.update(s => ({ ...s, [matchId]: true }));
    this.api.delete<any>(`h2h/${matchId}`).subscribe({
      next: () => {
        this.deleteStates.update(s => { const n = { ...s }; delete n[matchId]; return n; });
        this.koReload$.next();
      },
      error: () => this.deleteStates.update(s => { const n = { ...s }; delete n[matchId]; return n; }),
    });
  }

  teamName(teamId: string): string {
    return this.teams().find(t => t.id === teamId)?.team_name ?? teamId;
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
