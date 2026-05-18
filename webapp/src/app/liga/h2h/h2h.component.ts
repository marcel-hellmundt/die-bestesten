import { Component, computed, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { catchError, combineLatest, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { AuthService } from '../../auth/auth.service';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-h2h',
  standalone: false,
  templateUrl: './h2h.component.html',
  styleUrl: './h2h.component.scss',
})
export class H2HComponent {
  private api    = inject(ApiService);
  private auth   = inject(AuthService);
  private router = inject(Router);
  cache          = inject(DataCacheService);

  isAdmin = this.auth.isAdmin();

  private seasons = computed(() =>
    [...this.cache.startedSeasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))
  );

  selectedIndex  = signal(0);
  selectedSeason = computed(() => this.seasons()[this.selectedIndex()] ?? null);
  selectedSeasonYear = computed(() => this.selectedSeason()?.start_date?.slice(0, 4) ?? '');
  canDecrement   = computed(() => this.selectedIndex() < this.seasons().length - 1);
  canIncrement   = computed(() => this.selectedIndex() > 0);
  decrement()    { if (this.canDecrement()) this.selectedIndex.update(i => i + 1); }
  increment()    { if (this.canIncrement()) this.selectedIndex.update(i => i - 1); }

  // reload trigger: incrementing re-fetches the overview
  reloadTrigger = signal(0);
  reload()      { this.reloadTrigger.update(n => n + 1); }

  // ── Overview data ──────────────────────────────────────────────────────────

  private state = toSignal(
    combineLatest([
      toObservable(this.selectedSeason).pipe(filter(s => !!s)),
      toObservable(this.reloadTrigger),
    ]).pipe(
      switchMap(([season]) =>
        this.api.get<any>(`h2h?season_id=${season!.id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' })),
        )
      )
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);
  groups  = computed(() => (this.state().data?.groups ?? []) as any[]);
  knockoutMatches = computed(() => (this.state().data?.knockout_matches ?? []) as any[]);

  // Group knockout matches by phase + normalized team pair
  knockoutPhases = computed(() => {
    const matches = this.knockoutMatches();
    const order   = ['quarterfinal', 'semifinal', 'final'];
    const byPhase = new Map<string, any[]>();
    for (const m of matches) {
      if (!byPhase.has(m.phase)) byPhase.set(m.phase, []);
      byPhase.get(m.phase)!.push(m);
    }
    return order
      .filter(p => byPhase.has(p))
      .map(phase => {
        const phaseMatches = byPhase.get(phase)!;
        const matchupMap   = new Map<string, any[]>();
        for (const m of phaseMatches) {
          const key = [m.home_team_id, m.away_team_id].sort().join('_');
          if (!matchupMap.has(key)) matchupMap.set(key, []);
          matchupMap.get(key)!.push(m);
        }
        const matchups = Array.from(matchupMap.values()).map(legs => {
          const sorted = [...legs].sort((a, b) => a.leg - b.leg);
          const teamAId = sorted[0].home_team_id;
          const teamBId = sorted[0].away_team_id;
          let aggA = 0, aggB = 0;
          let hasResults = false;
          for (const leg of sorted) {
            if (leg.home_points != null) {
              hasResults = true;
              aggA += leg.home_team_id === teamAId ? leg.home_points : leg.away_points;
              aggB += leg.home_team_id === teamBId ? leg.home_points : leg.away_points;
            }
          }
          return { legs: sorted, teamAId, teamBId, aggA, aggB, hasResults };
        });
        return { phase, label: this.phaseLabel(phase), matchups };
      });
  });

  phaseLabel(phase: string): string {
    return ({ quarterfinal: 'Viertelfinale', semifinal: 'Halbfinale', final: 'Finale' } as any)[phase] ?? phase;
  }

  navigateToMatch(id: string): void {
    this.router.navigate(['/liga/h2h', id]);
  }

  // ── Team logos ─────────────────────────────────────────────────────────────

  private logoErrors = new Set<string>();

  teamLogoUrl(teamId: string): string {
    const sid = this.selectedSeason()?.id ?? '';
    return `${environment.imageApiUrl}/img/team/${sid}/${teamId}.png`;
  }

  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  // ── Admin: team + matchday lists ───────────────────────────────────────────

  private teamsState = toSignal(
    toObservable(this.selectedSeason).pipe(
      filter(s => !!s),
      switchMap(season =>
        this.api.get<any[]>(`team?season_id=${season!.id}`).pipe(catchError(() => of([])))
      )
    ),
    { initialValue: [] as any[] }
  );

  private matchdaysState = toSignal(
    toObservable(this.selectedSeason).pipe(
      filter(s => !!s),
      switchMap(season =>
        this.api.get<any[]>(`matchday?season_id=${season!.id}`).pipe(catchError(() => of([])))
      )
    ),
    { initialValue: [] as any[] }
  );

  adminTeams     = computed(() => (this.teamsState() ?? []) as any[]);
  adminMatchdays = computed(() =>
    [...((this.matchdaysState() ?? []) as any[])].sort((a, b) => a.number - b.number)
  );

  // ── Admin: group creation ──────────────────────────────────────────────────

  newGroupName  = signal('');
  groupSubmit   = signal<'idle' | 'loading' | 'error'>('idle');
  groupError    = signal<string | null>(null);

  submitGroup(): void {
    const season = this.selectedSeason();
    const name   = this.newGroupName().trim();
    if (!season || !name) return;
    this.groupSubmit.set('loading');
    this.groupError.set(null);
    this.api.post('h2h_group', { season_id: season.id, name, sort_index: this.groups().length }).subscribe({
      next: () => { this.newGroupName.set(''); this.groupSubmit.set('idle'); this.reload(); },
      error: (e: any) => { this.groupSubmit.set('error'); this.groupError.set(e?.error?.message ?? 'Fehler'); },
    });
  }

  deleteGroup(id: string): void {
    this.api.delete(`h2h_group/${id}`).subscribe({ next: () => this.reload(), error: () => {} });
  }

  toggleGroupTeam(groupId: string, teamId: string, checked: boolean): void {
    const group   = this.groups().find((g: any) => g.id === groupId);
    if (!group) return;
    const teams   = checked
      ? [...group.teams, teamId]
      : group.teams.filter((id: string) => id !== teamId);
    this.api.patch(`h2h_group/${groupId}`, { teams }).subscribe({ next: () => this.reload(), error: () => {} });
  }

  // ── Admin: match creation ──────────────────────────────────────────────────

  readonly phases = ['group', 'quarterfinal', 'semifinal', 'final'];

  newMatchPhase    = signal<string>('group');
  newMatchLeg      = signal<number>(1);
  newMatchHome     = signal<string>('');
  newMatchAway     = signal<string>('');
  newMatchMatchday = signal<string>('');
  newMatchGroup    = signal<string>('');
  matchSubmit      = signal<'idle' | 'loading' | 'error'>('idle');
  matchError       = signal<string | null>(null);

  submitMatch(): void {
    const season = this.selectedSeason();
    if (!season || !this.newMatchHome() || !this.newMatchAway() || !this.newMatchMatchday()) return;
    this.matchSubmit.set('loading');
    this.matchError.set(null);
    const totalExisting = this.knockoutMatches().length +
      this.groups().reduce((s: number, g: any) => s + g.matches.length, 0);
    const body: any = {
      season_id:    season.id,
      phase:        this.newMatchPhase(),
      leg:          this.newMatchLeg(),
      home_team_id: this.newMatchHome(),
      away_team_id: this.newMatchAway(),
      matchday_id:  this.newMatchMatchday(),
      sort_index:   totalExisting,
    };
    if (this.newMatchPhase() === 'group' && this.newMatchGroup()) body.group_id = this.newMatchGroup();
    this.api.post('h2h', body).subscribe({
      next: () => { this.matchSubmit.set('idle'); this.reload(); },
      error: (e: any) => { this.matchSubmit.set('error'); this.matchError.set(e?.error?.message ?? 'Fehler'); },
    });
  }

  deleteMatch(id: string): void {
    this.api.delete(`h2h/${id}`).subscribe({ next: () => this.reload(), error: () => {} });
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
