import { Component, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, Subject, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
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
  stake: number | null;
  payout: number | null;
  result: 'open' | 'won' | 'lost';
}

interface WonMatch {
  match_id: string;
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
}

interface StandingRow {
  manager_id: string;
  manager_name: string;
  alias: string | null;
  wins: number;
  won_matches: WonMatch[];
}

interface BudgetStandingRow {
  manager_id: string;
  manager_name: string;
  alias: string | null;
  budget: number;
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
  private api   = inject(ApiService);
  private cache = inject(DataCacheService);

  // Nach einem erfolgreich abgegebenen Tipp (siehe submitAvailablePrediction()) müssen sowohl die
  // eigene Tipp-Liste (neuer Eintrag) als auch die Liste der noch offenen Matches (dieses Match
  // verschwindet daraus) und das eigene Lukaten-Budget neu geladen werden.
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

  // Anders als die Sieg-Bestenliste (ändert sich nur bei Spieltagsabschluss) ändert sich das
  // Lukaten-Ranking direkt beim Setzen eines Einsatzes — deshalb an refresh$ gekoppelt.
  private budgetStandingsState = toSignal(
    this.refresh$.pipe(
      startWith(null),
      switchMap(() =>
        this.api.get<BudgetStandingRow[]>('h2h_prediction/budget_standings').pipe(
          map(data => ({ data, loading: false })),
          startWith({ data: [] as BudgetStandingRow[], loading: true }),
          catchError(() => of({ data: [] as BudgetStandingRow[], loading: false })),
        )
      ),
    ),
    { initialValue: { data: [] as BudgetStandingRow[], loading: true } },
  );

  private budgetState = toSignal(
    this.refresh$.pipe(
      startWith(null),
      switchMap(() =>
        this.api.get<{ budget: number }>('h2h_prediction/budget').pipe(
          map(data => ({ data: data.budget, loading: false })),
          startWith({ data: null as number | null, loading: true }),
          catchError(() => of({ data: null as number | null, loading: false })),
        )
      ),
    ),
    { initialValue: { data: null as number | null, loading: true } },
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

  bets                  = computed(() => this.betsState().data);
  betsLoading           = computed(() => this.betsState().loading);
  standings             = computed(() => this.standingsState().data);
  standingsLoading      = computed(() => this.standingsState().loading);
  budgetStandings        = computed(() => this.budgetStandingsState().data);
  budgetStandingsLoading = computed(() => this.budgetStandingsState().loading);
  availableMatches      = computed(() => this.availableState().data);
  availableLoading      = computed(() => this.availableState().loading);

  // Optimistischer Override, damit die Budget-Anzeige nach einer Tippabgabe sofort den vom
  // Server zurückgegebenen neuen Wert zeigt, statt auf den refresh$-Roundtrip zu warten.
  private budgetOverride = signal<number | undefined>(undefined);
  budget = computed(() => this.budgetOverride() ?? this.budgetState().data);

  betsFilter   = signal<'all' | 'open' | 'won'>('all');
  filteredBets = computed(() => {
    const filter = this.betsFilter();
    return filter === 'all' ? this.bets() : this.bets().filter(b => b.result === filter);
  });
  wonCount     = computed(() => this.bets().filter(b => b.result === 'won').length);
  totalCount   = computed(() => this.bets().length);

  // "Historie" der linken Spalte: eigene Tipps DIESER Saison mit gesetztem Einsatz, neueste
  // zuerst (bets() ist von /h2h_prediction/mine bereits so sortiert).
  activeSeasonId = computed(() => {
    const seasons = [...this.cache.startedSeasons()].sort((a, b) => b.start_date.localeCompare(a.start_date));
    return seasons[0]?.id ?? null;
  });
  stakedHistory = computed(() => {
    const seasonId = this.activeSeasonId();
    return this.bets().filter(b => b.stake != null && b.season_id === seasonId);
  });

  pickLabel(b: Bet): string {
    if (b.pick === 'draw') return 'Unentschieden';
    return b.pick === 'home' ? b.home_team_name : b.away_team_name;
  }

  pickCode(pick: 'home' | 'draw' | 'away'): '1' | 'X' | '2' {
    if (pick === 'home') return '1';
    if (pick === 'away') return '2';
    return 'X';
  }

  wonMatchPickLabel(w: WonMatch): string {
    if (w.pick === 'draw') return 'Unentschieden';
    return w.pick === 'home' ? w.home_team_name : w.away_team_name;
  }

  teamLogoUrl(teamId: string, seasonId: string | null): string {
    return `${environment.imageApiUrl}/team/${seasonId ?? ''}/${teamId}.png`;
  }

  formatLukaten(v: number | null): string {
    if (v == null) return '–';
    const s = Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ',');
    return `${s} Lukaten`;
  }

  // Nur die Zahl, ohne "Lukaten"-Suffix — für Stellen, an denen das lukat.png-Icon direkt daneben
  // steht und den Suffix bereits visuell ersetzt (siehe .h2h-stake-row__budget).
  formatLukatenNumber(v: number | null): string {
    if (v == null) return '–';
    return Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ',');
  }

  private logoErrors = new Set<string>();
  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  range(n: number): number[] { return Array.from({ length: n }, (_, i) => i); }

  // ── Tipp direkt aus der Liste der offenen Matches abgeben (gleiche UI/Endpoint wie
  // h2h-match.component.ts's submitPrediction()) ────────────────────────────────────
  submittingMatchId = signal<string | null>(null);
  predictionError   = signal<string | null>(null);

  // Einsatz je offenem Match (mehrere Karten gleichzeitig sichtbar, jede mit eigenem Feld) —
  // available-Matches enthalten laut Backend nie einen bereits bestehenden eigenen Tipp, das
  // verfügbare Maximum ist also immer schlicht das aktuelle Budget (kein Exclude nötig).
  private stakeByMatch = signal<Record<string, number | null>>({});

  stakeForMatch(matchId: string): number | null {
    return this.stakeByMatch()[matchId] ?? null;
  }

  onAvailableStakeChange(matchId: string, raw: string): void {
    const n = raw.trim() === '' ? null : Math.trunc(+raw);
    this.stakeByMatch.update(m => ({ ...m, [matchId]: n !== null && Number.isFinite(n) ? n : null }));
  }

  submitAvailablePrediction(m: AvailableMatch, pick: 'home' | 'draw' | 'away'): void {
    if (this.submittingMatchId()) return;

    const stake = this.stakeForMatch(m.match_id);
    const maxStake = this.budget() ?? 0;
    if (stake !== null && (!Number.isInteger(stake) || stake < 1 || stake > maxStake)) {
      this.predictionError.set(`Einsatz muss eine ganze Zahl zwischen 1 und ${maxStake} sein.`);
      return;
    }

    this.submittingMatchId.set(m.match_id);
    this.predictionError.set(null);

    this.api.post<{ status: boolean; message?: string; budget?: number }>('h2h_prediction', {
      match_id: m.match_id,
      pick,
      odds: m.odds[pick] ?? null,
      stake,
    }).subscribe({
      next: (res) => {
        this.submittingMatchId.set(null);
        if (res.budget !== undefined) this.budgetOverride.set(res.budget);
        this.refresh$.next();
      },
      error: (err: any) => {
        this.submittingMatchId.set(null);
        this.predictionError.set(err?.error?.message ?? 'Tipp konnte nicht gespeichert werden.');
      },
    });
  }

  // ── Hover-Tooltip über einem Reward-Icon: zeigt beide Vereinslogos, Endergebnis und
  // individuell eingelockte Quote — gleiches Edge-Clamp-Muster wie liga-teams.component.ts's
  // onListHover()/onListLeave(), hier für ein einzelnes Match-Objekt statt einer Spielerliste.
  @ViewChild('winTooltipEl') winTooltipEl?: ElementRef<HTMLElement>;
  tooltipMatch = signal<WonMatch | null>(null);
  tooltipPos   = signal<{ top: number; left: number } | null>(null);
  tooltipBelow = signal(false);
  tooltipReady = signal(false);

  private static readonly TOOLTIP_EDGE_MARGIN = 24;
  private hoverSeq = 0;

  // Number(...) statt w.odds.toFixed() direkt — DECIMAL-Spalten kommen vom Backend zwar schon als
  // Zahl (siehe getH2HPredictionStandings()), diese Absicherung verhindert trotzdem einen Crash,
  // falls hier je wieder ein String durchrutscht.
  tooltipOdds(w: WonMatch): string | null {
    const odds = w.odds != null ? Number(w.odds) : null;
    return odds != null && !Number.isNaN(odds) ? odds.toFixed(2).replace('.', ',') : null;
  }

  onWinHover(event: MouseEvent, w: WonMatch): void {
    const seq = ++this.hoverSeq;
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.tooltipMatch.set(w);
    this.tooltipBelow.set(false);
    this.tooltipReady.set(false);
    this.tooltipPos.set({ top: rect.top, left: rect.left + rect.width / 2 });

    requestAnimationFrame(() => {
      const el = this.winTooltipEl?.nativeElement;
      if (!el || seq !== this.hoverSeq) return;

      const margin = BettingOfficeComponent.TOOLTIP_EDGE_MARGIN;
      let top   = rect.top;
      let left  = rect.left + rect.width / 2;
      let below = false;

      const tipRect = el.getBoundingClientRect();

      if (tipRect.top < margin) {
        below = true;
        top = rect.bottom;
      }

      const halfWidth = tipRect.width / 2;
      const maxLeft   = window.innerWidth - margin - halfWidth;
      const minLeft   = margin + halfWidth;
      if (left > maxLeft) left = maxLeft;
      if (left < minLeft) left = minLeft;

      this.tooltipBelow.set(below);
      this.tooltipPos.set({ top, left });
      this.tooltipReady.set(true);
    });
  }

  onWinLeave(): void {
    this.hoverSeq++;
    this.tooltipMatch.set(null);
    this.tooltipPos.set(null);
    this.tooltipReady.set(false);
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
