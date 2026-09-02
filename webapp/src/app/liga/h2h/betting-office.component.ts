import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, Subject, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { environment } from '../../../environments/environment';

interface Bet {
  match_id: string;
  matchday_number: number | null;
  season_id: string | null;
  home_team_id: string;
  home_team_name: string;
  home_color: string | null;
  away_team_id: string;
  away_team_name: string;
  away_color: string | null;
  home_goals: number | null;
  away_goals: number | null;
  pick: 'home' | 'draw' | 'away';
  odds: number | null;
  result: 'open' | 'won' | 'lost';
}

interface StandingRow {
  manager_id: string;
  manager_name: string;
  alias: string | null;
  wins: number;
}

interface MatchTeam {
  id: string;
  team_name: string;
  color: string | null;
  color_secondary: string | null;
  season_id: string;
  manager_id: string;
  manager_name: string;
  alias: string | null;
}

interface AvailableMatch {
  match_id: string;
  matchday_number: number | null;
  season_id: string;
  home_team: MatchTeam;
  away_team: MatchTeam;
  odds: { home: number | null; draw: number | null; away: number | null };
}

@Component({
  selector: 'app-betting-office',
  standalone: false,
  templateUrl: './betting-office.component.html',
  styleUrl: './betting-office.component.scss',
})
export class BettingOfficeComponent {
  private api = inject(ApiService);

  // Nach einem erfolgreich abgegebenen Tipp (siehe submitAvailablePrediction()) müssen sowohl die
  // eigene Tipp-Liste (neuer Eintrag) als auch die Liste der noch offenen Matches (dieses Match
  // verschwindet daraus) neu geladen werden.
  private refresh$ = new Subject<void>();

  private betsState = toSignal(
    this.refresh$.pipe(
      startWith(null),
      switchMap(() =>
        this.api.get<Bet[]>('h2h_prediction/mine').pipe(
          map(data => ({ data, loading: false })),
          startWith({ data: [] as Bet[], loading: true }),
          catchError(() => of({ data: [] as Bet[], loading: false })),
        )
      ),
    ),
    { initialValue: { data: [] as Bet[], loading: true } },
  );

  private standingsState = toSignal(
    this.api.get<StandingRow[]>('h2h_prediction/standings').pipe(
      map(data => ({ data, loading: false })),
      startWith({ data: [] as StandingRow[], loading: true }),
      catchError(() => of({ data: [] as StandingRow[], loading: false })),
    ),
    { initialValue: { data: [] as StandingRow[], loading: true } },
  );

  private availableState = toSignal(
    this.refresh$.pipe(
      startWith(null),
      switchMap(() =>
        this.api.get<AvailableMatch[]>('h2h_prediction/available').pipe(
          map(data => ({ data, loading: false })),
          startWith({ data: [] as AvailableMatch[], loading: true }),
          catchError(() => of({ data: [] as AvailableMatch[], loading: false })),
        )
      ),
    ),
    { initialValue: { data: [] as AvailableMatch[], loading: true } },
  );

  bets              = computed(() => this.betsState().data);
  betsLoading       = computed(() => this.betsState().loading);
  standings         = computed(() => this.standingsState().data);
  standingsLoading  = computed(() => this.standingsState().loading);
  availableMatches  = computed(() => this.availableState().data);
  availableLoading  = computed(() => this.availableState().loading);

  betsFilter   = signal<'all' | 'open' | 'won'>('all');
  filteredBets = computed(() => {
    const filter = this.betsFilter();
    return filter === 'all' ? this.bets() : this.bets().filter(b => b.result === filter);
  });
  wonCount     = computed(() => this.bets().filter(b => b.result === 'won').length);
  totalCount   = computed(() => this.bets().length);

  pickLabel(b: Bet): string {
    if (b.pick === 'draw') return 'Unentschieden';
    return b.pick === 'home' ? b.home_team_name : b.away_team_name;
  }

  teamLogoUrl(teamId: string, seasonId: string | null): string {
    return `${environment.imageApiUrl}/team/${seasonId ?? ''}/${teamId}.png`;
  }

  private logoErrors = new Set<string>();
  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  range(n: number): number[] { return Array.from({ length: n }, (_, i) => i); }

  // ── Tipp direkt aus der Liste der offenen Matches abgeben (gleiche UI/Endpoint wie
  // h2h-match.component.ts's submitPrediction()) ────────────────────────────────────
  submittingMatchId = signal<string | null>(null);
  predictionError   = signal<string | null>(null);

  submitAvailablePrediction(m: AvailableMatch, pick: 'home' | 'draw' | 'away'): void {
    if (this.submittingMatchId()) return;
    this.submittingMatchId.set(m.match_id);
    this.predictionError.set(null);

    this.api.post<{ status: boolean; message?: string }>('h2h_prediction', {
      match_id: m.match_id,
      pick,
      odds: m.odds[pick] ?? null,
    }).subscribe({
      next: () => {
        this.submittingMatchId.set(null);
        this.refresh$.next();
      },
      error: (err: any) => {
        this.submittingMatchId.set(null);
        this.predictionError.set(err?.error?.message ?? 'Tipp konnte nicht gespeichert werden.');
      },
    });
  }
}
