import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
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

interface AvailableMatch {
  match_id: string;
  matchday_number: number | null;
  home_team_name: string;
  away_team_name: string;
}

@Component({
  selector: 'app-betting-office',
  standalone: false,
  templateUrl: './betting-office.component.html',
  styleUrl: './betting-office.component.scss',
})
export class BettingOfficeComponent {
  private api = inject(ApiService);

  private betsState = toSignal(
    this.api.get<Bet[]>('h2h_prediction/mine').pipe(
      map(data => ({ data, loading: false })),
      startWith({ data: [] as Bet[], loading: true }),
      catchError(() => of({ data: [] as Bet[], loading: false })),
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
    this.api.get<AvailableMatch[]>('h2h_prediction/available').pipe(
      map(data => ({ data, loading: false })),
      startWith({ data: [] as AvailableMatch[], loading: true }),
      catchError(() => of({ data: [] as AvailableMatch[], loading: false })),
    ),
    { initialValue: { data: [] as AvailableMatch[], loading: true } },
  );

  bets              = computed(() => this.betsState().data);
  betsLoading       = computed(() => this.betsState().loading);
  standings         = computed(() => this.standingsState().data);
  standingsLoading  = computed(() => this.standingsState().loading);
  availableMatches  = computed(() => this.availableState().data);

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
}
