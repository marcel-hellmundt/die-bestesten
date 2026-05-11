import { Component, computed, effect, inject, signal, TemplateRef, ViewChild } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, combineLatest, distinctUntilChanged, map, merge, of, Subject, switchMap, startWith } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';

interface TeamHistoryEntry {
  team_id: string;
  season_id: string;
  team_name: string;
  color: string | null;
  manager_name: string;
  alias: string | null;
  from_matchday_number: number | null;
  to_matchday_number: number | null;
}

interface PlayerInSeason {
  season_id: string;
  price: number;
  position: 'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD';
  photo_uploaded: number;
  season_start: string;
  total_points: string;
}

interface PlayerInClub {
  club_id: string;
  club_name: string;
  logo_uploaded: number;
  from_date: string;
  to_date: string | null;
  on_loan: number;
}

interface PlayerRating {
  id: string;
  grade: string | null;
  participation: 'starting' | 'substitute' | null;
  goals: string;
  assists: string;
  clean_sheet: string;
  sds: string;
  red_card: string;
  yellow_red_card: string;
  points: string | null;
  club_id: string;
  club_logo_uploaded: string;
  matchday_number: string;
  kickoff_date: string;
}

interface PlayerDetail {
  id: string;
  country_id: string | null;
  first_name: string | null;
  last_name: string | null;
  displayname: string;
  birth_city: string | null;
  date_of_birth: string | null;
  height_cm: number | null;
  weight_kg: number | null;
  seasons: PlayerInSeason[];
  clubs: PlayerInClub[];
  ratings: PlayerRating[];
}

@Component({
  selector: 'app-player-detail',
  standalone: false,
  templateUrl: './player-detail.component.html',
  styleUrl: './player-detail.component.scss',
})
export class PlayerDetailComponent {
  private api    = inject(ApiService);
  private route  = inject(ActivatedRoute);
  private auth   = inject(AuthService);
  private router = inject(Router);
  cache          = inject(DataCacheService);
  bottomSheet    = inject(BottomSheetService);

  @ViewChild('offerSheet') offerSheet!: TemplateRef<any>;

  navigateToTeam(teamId: string): void { this.router.navigate(['/team', teamId]); }
  navigateToClub(clubId: string): void { this.router.navigate(['../../club', clubId], { relativeTo: this.route }); }

  private id$ = this.route.paramMap.pipe(map((p) => p.get('id')!));

  selectedSeasonId = signal<string | null>(null);
  private selectedSeason$ = toObservable(this.selectedSeasonId);
  private reloadPlayer$ = new BehaviorSubject<void>(undefined);

  private state = toSignal(
    combineLatest([this.id$, this.selectedSeason$, this.reloadPlayer$]).pipe(
      switchMap(([id, seasonId]) => {
        const url = seasonId ? `player/${id}?season_id=${seasonId}` : `player/${id}`;
        return this.api.get<PlayerDetail>(url).pipe(
          map((data) => ({ data, loading: false, error: null as string | null })),
          catchError(() =>
            of({ data: null as PlayerDetail | null, loading: false, error: 'Fehler beim Laden' }),
          ),
        );
      }),
    ),
  );

  player  = computed(() => this.state()?.data ?? null);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error ?? null);

  isMaintainer = computed(() => this.auth.isMaintainer());

  allClubs = toSignal(
    this.api.get<{ id: string; name: string }[]>('club').pipe(
      map(clubs => [...clubs].sort((a, b) => a.name.localeCompare(b.name))),
      catchError(() => of([] as { id: string; name: string }[]))
    ),
    { initialValue: [] as { id: string; name: string }[] }
  );

  // Add-club form state
  showAddClubForm = signal(false);
  newClubId       = signal('');
  newFromDate     = signal(new Date().toISOString().slice(0, 10));
  newOnLoan       = signal(false);
  addingClub      = signal(false);
  addClubError    = signal<string | null>(null);

  openAddClubForm(): void {
    this.newClubId.set(this.allClubs()[0]?.id ?? '');
    this.newFromDate.set(new Date().toISOString().slice(0, 10));
    this.newOnLoan.set(false);
    this.addClubError.set(null);
    this.showAddClubForm.set(true);
  }

  cancelAddClub(): void {
    this.showAddClubForm.set(false);
    this.addClubError.set(null);
  }

  submitAddClub(): void {
    const p = this.player();
    if (!p || !this.newClubId() || !this.newFromDate() || this.addingClub()) return;

    this.addingClub.set(true);
    this.addClubError.set(null);
    this.api.post<{ id: string }>('player_in_club', {
      player_id: p.id,
      club_id:   this.newClubId(),
      from_date: this.newFromDate(),
      on_loan:   this.newOnLoan(),
    }).subscribe({
      next: () => {
        this.addingClub.set(false);
        this.showAddClubForm.set(false);
        this.reloadPlayer$.next();
      },
      error: (err: any) => {
        this.addingClub.set(false);
        this.addClubError.set(err?.error?.message ?? 'Fehler beim Speichern');
      },
    });
  }

  private refreshTeam$ = new Subject<void>();

  currentTeam = toSignal(
    merge(this.id$, this.refreshTeam$.pipe(switchMap(() => this.id$))).pipe(
      switchMap(id =>
        this.api.get<{ id: string; season_id: string; team_name: string; color: string | null; manager_id: string; manager_name: string; alias: string | null } | null>(
          `player_in_team?player_id=${id}`
        ).pipe(
          catchError(() => of(null)),
          startWith(undefined)
        )
      )
    ),
    { initialValue: undefined as any }
  );

  isOwnTeam = computed(() => !!this.currentTeam() && this.currentTeam()!.manager_id === this.auth.getManagerId());

  myTeam = toSignal(
    this.api.get<{ id: string; team_name: string; season_id: string; color: string | null } | null>('team/mine').pipe(
      catchError(() => of(null))
    ),
    { initialValue: null as { id: string; team_name: string; season_id: string; color: string | null } | null }
  );

  watchlistEntryId = signal<string | null>(null);
  isWatched        = computed(() => this.watchlistEntryId() !== null);
  watchToggling    = signal(false);

  private watchlistEntries = toSignal(
    toObservable(this.myTeam).pipe(
      switchMap(team => {
        if (!team) return of([] as { id: string; player_id: string }[]);
        return this.api.get<{ id: string; player_id: string }[]>(`watchlist?team_id=${team.id}`).pipe(
          catchError(() => of([] as { id: string; player_id: string }[]))
        );
      })
    ),
    { initialValue: [] as { id: string; player_id: string }[] }
  );

  private playerId = toSignal(this.id$, { initialValue: '' });

  toggleWatch(): void {
    const team = this.myTeam();
    const p    = this.player();
    if (!team || !p || this.watchToggling()) return;

    this.watchToggling.set(true);
    if (this.isWatched()) {
      this.api.delete<null>(`watchlist/${this.watchlistEntryId()}`, { team_id: team.id }).subscribe({
        next: () => { this.watchlistEntryId.set(null); this.watchToggling.set(false); },
        error: () => this.watchToggling.set(false),
      });
    } else {
      this.api.post<{ id: string }>('watchlist', { team_id: team.id, player_id: p.id }).subscribe({
        next: (res) => { this.watchlistEntryId.set(res.id); this.watchToggling.set(false); },
        error: () => this.watchToggling.set(false),
      });
    }
  }

  private refreshOffers$ = new Subject<void>();

  myBudget = toSignal(
    combineLatest([toObservable(this.myTeam), this.refreshOffers$.pipe(startWith(null))]).pipe(
      switchMap(([t]) => {
        if (!t) return of(0);
        return this.api.get<{ budget: number }>(`transaction?team_id=${t.id}`).pipe(
          map(r => +r.budget),
          catchError(() => of(0))
        );
      })
    ),
    { initialValue: 0 }
  );

  myOfferData = toSignal(
    combineLatest([toObservable(this.myTeam), this.refreshOffers$.pipe(startWith(null))]).pipe(
      switchMap(([t]) => {
        if (!t) return of({ offers: [] as any[], pending_sum: 0 });
        return this.api.get<{ offers: any[]; pending_sum: number }>(`offer?team_id=${t.id}`).pipe(
          catchError(() => of({ offers: [] as any[], pending_sum: 0 }))
        );
      })
    ),
    { initialValue: { offers: [] as any[], pending_sum: 0 } }
  );

  mySquad = toSignal(
    combineLatest([toObservable(this.myTeam), this.refreshOffers$.pipe(startWith(null))]).pipe(
      switchMap(([t]) => {
        if (!t) return of([] as any[]);
        return this.api.get<any[]>(`player_in_team?team_id=${t.id}`).pipe(
          catchError(() => of([] as any[]))
        );
      })
    ),
    { initialValue: [] as any[] }
  );

  private readonly SQUAD_MAX: Record<string, number> = {
    GOALKEEPER: 2, DEFENDER: 6, MIDFIELDER: 6, FORWARD: 4,
  };

  positionLimitReached = computed(() => {
    const position = this.player()?.seasons?.[0]?.position;
    if (!position || !this.SQUAD_MAX[position]) return false;
    const max          = this.SQUAD_MAX[position];
    const squadCount   = this.mySquad().filter((p: any) => p.position === position).length;
    const pendingCount = this.myOfferData().offers.filter(o => o.status === 'pending' && o.position === position).length;
    return squadCount + pendingCount >= max;
  });

  availableBudget = computed(() => this.myBudget() - (this.myOfferData().pending_sum ?? 0));

  myPendingOffer = computed(() => {
    const p = this.player();
    if (!p) return null;
    return this.myOfferData().offers.find(
      (o: any) => o.player_id === p.id && o.status === 'pending'
    ) ?? null;
  });

  private effectiveSeasonId$ = combineLatest([
    this.selectedSeason$,
    toObservable(this.player),
  ]).pipe(
    map(([selected, p]) => selected ?? p?.seasons[0]?.season_id ?? null),
    distinctUntilChanged(),
  );

  teamHistory = toSignal(
    combineLatest([this.id$, this.effectiveSeasonId$]).pipe(
      switchMap(([id, seasonId]) => {
        if (!seasonId) return of([] as TeamHistoryEntry[]);
        return this.api.get<TeamHistoryEntry[]>(`player_in_team?player_id=${id}&season_id=${seasonId}`).pipe(
          catchError(() => of([] as TeamHistoryEntry[]))
        );
      })
    ),
    { initialValue: [] as TeamHistoryEntry[] }
  );

  lineupNominations = toSignal(
    combineLatest([this.id$, this.effectiveSeasonId$]).pipe(
      switchMap(([id, seasonId]) => {
        if (!seasonId) return of(new Map<number, boolean>());
        return this.api.get<{ matchday_number: number; nominated: boolean }[]>(
          `team_lineup?player_id=${id}&season_id=${seasonId}`
        ).pipe(
          map(entries => new Map(entries.map(e => [e.matchday_number, e.nominated]))),
          catchError(() => of(new Map<number, boolean>()))
        );
      })
    ),
    { initialValue: new Map<number, boolean>() }
  );

  // Offer panel
  offerSubmitting = signal(false);
  offerError      = signal<string | null>(null);
  offerSuccess    = signal(false);

  // Digit spinner — 4 controllable digits (10M / 1M / 100K / 10K), granularity 10.000
  digitE10000000 = signal(0);
  digitE1000000  = signal(0);
  digitE100000   = signal(0);
  digitE10000    = signal(0);

  offerValue = computed(() =>
    this.digitE10000000() * 10_000_000 +
    this.digitE1000000()  *  1_000_000 +
    this.digitE100000()   *    100_000 +
    this.digitE10000()    *     10_000
  );

  marketValue = computed(() => {
    const price = +(this.player()?.seasons?.[0]?.price ?? 0);
    return Math.round(price + this.totalPoints() * 20_000);
  });

  offerPercentage = computed(() => {
    const mv = this.marketValue();
    if (!mv) return 0;
    return Math.round(this.offerValue() / mv * 100);
  });

  sliderPct = computed(() => Math.min(200, Math.max(100, this.offerPercentage())));

  isValidOffer = computed(() => {
    const mv = this.marketValue();
    return mv > 0
      && this.offerValue() >= mv
      && this.offerValue() <= this.availableBudget();
  });

  private setDigitsFromValue(v: number): void {
    const s = String(Math.max(0, Math.floor(v / 10_000) * 10_000)).padStart(8, '0');
    this.digitE10000000.set(+s[s.length - 8] || 0);
    this.digitE1000000.set( +s[s.length - 7] || 0);
    this.digitE100000.set(  +s[s.length - 6] || 0);
    this.digitE10000.set(   +s[s.length - 5] || 0);
  }

  openOffer(): void {
    this.offerSuccess.set(false);
    this.offerError.set(null);
    this.setDigitsFromValue(this.marketValue());
    this.bottomSheet.open(this.offerSheet, { title: 'Gebot abgeben' });
  }

  closeOffer(): void {
    this.bottomSheet.close();
    this.offerSuccess.set(false);
  }

  updateDigit(prop: 'digitE10000000' | 'digitE1000000' | 'digitE100000' | 'digitE10000', delta: number): void {
    const sigs: Record<string, ReturnType<typeof signal<number>>> = {
      digitE10000000: this.digitE10000000,
      digitE1000000:  this.digitE1000000,
      digitE100000:   this.digitE100000,
      digitE10000:    this.digitE10000,
    };
    sigs[prop].update(v => v + delta);

    // Carry-over logic
    if (this.digitE10000() > 9)  { this.digitE100000.update(v => v + 1);   this.digitE10000.set(0); }
    if (this.digitE10000() < 0)  { this.digitE10000.set(0); }
    if (this.digitE100000() > 9) { this.digitE1000000.update(v => v + 1);  this.digitE100000.set(0); }
    if (this.digitE100000() < 0) { this.digitE100000.set(0); }
    if (this.digitE1000000() > 9){ this.digitE10000000.update(v => v + 1); this.digitE1000000.set(0); }
    if (this.digitE1000000() < 0){ this.digitE1000000.set(0); }
    if (this.digitE10000000() > 9) { this.digitE10000000.set(9); }
    if (this.digitE10000000() < 0) { this.digitE10000000.set(0); }
  }

  onSliderChange(pct: number): void {
    const raw = Math.round(pct / 100 * this.marketValue() / 10_000) * 10_000;
    this.setDigitsFromValue(Math.min(raw, this.availableBudget()));
  }

  onAllIn(): void {
    this.setDigitsFromValue(this.availableBudget());
  }

  submitOffer(): void {
    const team = this.myTeam();
    const win  = this.openWindow();
    const p    = this.player();
    if (!team || !win || !p || !this.isValidOffer()) return;
    this.offerSubmitting.set(true);
    this.offerError.set(null);
    this.api.post<any>('offer', {
      team_id: team.id, player_id: p.id,
      transferwindow_id: win.id, offer_value: this.offerValue(),
    }).subscribe({
      next: () => {
        this.offerSubmitting.set(false);
        this.offerSuccess.set(true);
        this.refreshOffers$.next();
      },
      error: (err: any) => {
        this.offerSubmitting.set(false);
        this.offerError.set(err?.error?.message ?? 'Fehler beim Abschicken');
      },
    });
  }

  openWindow = toSignal(
    combineLatest([toObservable(this.currentTeam), toObservable(this.myTeam)]).pipe(
      switchMap(([team, myTeam]) => {
        const seasonId = team?.season_id ?? myTeam?.season_id;
        if (!seasonId) return of(null);
        return this.api.get<any[]>(`transferwindow?season_id=${seasonId}`).pipe(
          map(windows => {
            const now = new Date();
            return windows.find(w => new Date(w.start_date) <= now && new Date(w.end_date) > now) ?? null;
          }),
          catchError(() => of(null))
        );
      })
    ),
    { initialValue: null }
  );

  selling   = signal(false);
  sellError = signal<string | null>(null);

  teamLogoError = signal(false);

  teamLogoUrl(team: { id: string; season_id: string }): string {
    return `https://img.die-bestesten.de/img/team/${team.season_id}/${team.id}.png`;
  }

  sellPlayer(): void {
    const team = this.currentTeam();
    const win  = this.openWindow();
    const p    = this.player();
    if (!team || !win || !p) return;

    const currentSeason = p.seasons.find(s => s.season_id === team.season_id) ?? p.seasons[0];
    const basePrice = currentSeason?.price ?? 0;
    const pts = this.totalPoints();
    const sellPrice = Math.round(+basePrice + pts * 20000);
    const formatted = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(sellPrice);

    if (!confirm(`${p.displayname} für ${formatted} verkaufen?`)) return;

    this.selling.set(true);
    this.sellError.set(null);
    this.api.post<any>('sell', { team_id: team.id, player_id: p.id, transferwindow_id: win.id }).subscribe({
      next: () => {
        this.selling.set(false);
        this.refreshTeam$.next();
      },
      error: (err: any) => {
        this.selling.set(false);
        this.sellError.set(err?.error?.message ?? 'Fehler beim Verkauf');
      },
    });
  }

  latestPhotoUrl = computed(() => {
    const p = this.player();
    if (!p) return null;
    const latest = p.seasons[0]; // sorted newest first
    if (!latest?.photo_uploaded) return null;
    return `https://img.die-bestesten.de/img/player/${latest.season_id}/${p.id}.png`;
  });

  private readonly positionColors: Record<string, string> = {
    FORWARD:    'var(--position-forward)',
    MIDFIELDER: 'var(--position-midfielder)',
    DEFENDER:   'var(--position-defender)',
    GOALKEEPER: 'var(--position-goalkeeper)',
  };

  private readonly positionLabels: Record<string, string> = {
    FORWARD: 'STU',
    MIDFIELDER: 'MIT',
    DEFENDER: 'ABW',
    GOALKEEPER: 'TOR',
  };

  positionColor(position: string): string {
    return this.positionColors[position] ?? '#999';
  }

  positionLabel(position: string): string {
    return this.positionLabels[position] ?? position;
  }

  clubLogoUrl(clubId: string, logoUploaded: number): string {
    return logoUploaded
      ? `https://img.die-bestesten.de/img/club/${clubId}.png`
      : 'img/placeholders/club.png';
  }

  formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
  }

  calcAge(dateStr: string): number {
    const today = new Date();
    const birth = new Date(dateStr);
    let age = today.getFullYear() - birth.getFullYear();
    const notYetHadBirthday =
      today.getMonth() < birth.getMonth() ||
      (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate());
    if (notYetHadBirthday) age--;
    return age;
  }

  formatPrice(price: number): string {
    return new Intl.NumberFormat('de-DE', {
      style: 'currency',
      currency: 'EUR',
      maximumFractionDigits: 0,
    }).format(price);
  }

  formatPriceShort(price: number): string {
    if (price >= 1_000_000) return (price / 1_000_000).toFixed(1).replace('.', ',') + ' M';
    if (price >= 1_000)     return (price / 1_000).toFixed(0) + ' T';
    return String(price);
  }

  range(n: number | string): number[] {
    return Array.from({ length: Math.max(0, +n) }, (_, i) => i);
  }

  gradeVar(grade: string | null): string {
    if (!grade) return 'var(--grade-unset)';
    return `var(--grade-${grade.replace('.', '')})`;
  }

  teamForMatchday(matchdayNumber: string): TeamHistoryEntry | null {
    const n = +matchdayNumber;
    return this.teamHistory().find(t =>
      (t.from_matchday_number == null || t.from_matchday_number <= n) &&
      (t.to_matchday_number   == null || t.to_matchday_number   >  n)
    ) ?? null;
  }

  isCurrentBundesligaPlayer = computed(() => {
    const player = this.player();
    const seasons = this.cache.seasons();
    if (!player || !seasons.length) return false;
    const activeSeason = [...seasons].sort((a, b) => b.start_date.localeCompare(a.start_date))[0];
    return player.clubs.some(c => c.to_date === null) && player.seasons.some(s => s.season_id === activeSeason.id);
  });

  totalPoints  = computed(() => this.player()?.ratings.reduce((s, r) => s + +(r.points ?? 0), 0) ?? 0);

  // Points per team entry (key = team_id + '_' + from_matchday_number), counting only nominated matchdays
  teamPoints = computed(() => {
    const history     = this.teamHistory();
    const ratings     = this.player()?.ratings ?? [];
    const nominations = this.lineupNominations();
    const map = new Map<string, number>();
    for (const rating of ratings) {
      const n         = +rating.matchday_number;
      const nominated = nominations.get(n) ?? true;
      if (!nominated) continue;
      const team = history.find(t =>
        (t.from_matchday_number == null || t.from_matchday_number <= n) &&
        (t.to_matchday_number   == null || t.to_matchday_number   >  n)
      );
      if (!team) continue;
      const key = team.team_id + '_' + team.from_matchday_number;
      map.set(key, (map.get(key) ?? 0) + +(rating.points ?? 0));
    }
    return map;
  });

  displayToMatchday(t: TeamHistoryEntry): string {
    if (t.to_matchday_number == null) return 'heute';
    if (t.from_matchday_number === t.to_matchday_number) return 'Sp. ' + t.to_matchday_number;
    return 'Sp. ' + (t.to_matchday_number - 1);
  }
  avgGrade     = computed(() => {
    const graded = (this.player()?.ratings ?? []).filter(r => r.grade !== null);
    if (!graded.length) return null;
    return (graded.reduce((s, r) => s + +r.grade!, 0) / graded.length).toFixed(2);
  });
  totalGoals   = computed(() => this.player()?.ratings.reduce((s, r) => s + +r.goals,   0) ?? 0);
  totalAssists = computed(() => this.player()?.ratings.reduce((s, r) => s + +r.assists, 0) ?? 0);

  // Points bar chart (per matchday, colored by grade)
  pointsChartData = computed(() => {
    const p = this.player();
    if (!p || p.ratings.length === 0) return null;

    const sorted = p.ratings; // already sorted by matchday_number ASC
    const rawPts = sorted.map((r) => +(r.points ?? 0));
    const maxPts = Math.max(...rawPts, 0);
    const minPts = Math.min(...rawPts, 0);
    const range  = Math.max(maxPts - minPts, 1);

    const plotW  = this.pointsChartW - this.padL - this.padR;
    const plotH  = this.chartH - this.padT - this.padB;
    const n      = sorted.length;
    const slotW  = plotW / n;
    const barW   = Math.min(slotW * 0.65, 40);

    // Y coordinate of the zero baseline
    const zeroY = this.padT + plotH * (maxPts / range);

    const bars = sorted.map((s, i) => {
      const pts  = +(s.points ?? 0);
      const barH = (Math.abs(pts) / range) * plotH;
      const x    = this.padL + i * slotW + (slotW - barW) / 2;
      const y    = pts >= 0 ? zeroY - barH : zeroY;
      return {
        x, y, width: barW, height: barH,
        color:   s.grade ? this.gradeVar(s.grade) : '#9ca3af',
        labelX:  this.padL + i * slotW + slotW / 2,
        label:   s.matchday_number,
        tooltip: `ST ${s.matchday_number}: ${pts} Pkt`,
        pts,
        grade:   s.grade ?? null,
        zeroY,
      };
    });

    const yTicks: { y: number; label: string }[] = [
      { y: zeroY, label: '0' },
    ];
    if (maxPts > 0) yTicks.unshift({ y: this.padT,        label: String(maxPts) });
    if (minPts < 0) yTicks.push(  { y: this.padT + plotH, label: String(minPts) });

    // Rolling average line (5-matchday window)
    const windowSize = 5;
    const rollingPts: Array<{ x: number; y: number }> = [];
    for (let i = windowSize - 1; i < sorted.length; i++) {
      const avg = rawPts.slice(i - windowSize + 1, i + 1).reduce((a, b) => a + b, 0) / windowSize;
      rollingPts.push({
        x: this.padL + i * slotW + slotW / 2,
        y: Math.max(this.padT, Math.min(this.padT + plotH, zeroY - (avg / range) * plotH)),
      });
    }
    const rollingLine = rollingPts.length >= 2
      ? rollingPts.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ')
      : null;

    return { bars, yTicks, rollingLine };
  });

  // Bar charts – widths tuned to their respective CSS containers
  // price chart: 1/3 grid column ≈ 320px; points chart: full-width ≈ 900px
  readonly chartW       = 380; // price chart (middle grid column)
  hoveredBarIndex = signal<number | null>(null);

  readonly pointsChartW = 900; // points chart (full-width row above grid)
  readonly chartH = 160;
  readonly padL   = 44;
  readonly padR   = 8;
  readonly padT   = 8;
  readonly padB   = 24;

  priceChartData = computed(() => {
    const p = this.player();
    if (!p || p.seasons.length === 0) return null;

    const dataMap = new Map(p.seasons.map(s => [s.season_id, s]));

    // Use all seasons from cache to build a complete timeline with gaps
    const allSeasons = [...this.cache.seasons()]
      .sort((a, b) => a.start_date.localeCompare(b.start_date));
    if (allSeasons.length === 0) return null;

    const playerStarts = p.seasons.map(s => s.season_start).sort();
    const minStart = playerStarts[0];
    const maxStart = playerStarts[playerStarts.length - 1];
    const slots = allSeasons.filter(s => s.start_date >= minStart && s.start_date <= maxStart);
    if (slots.length === 0) return null;

    const maxPrice = Math.max(...p.seasons.map(s => s.price), 1);
    const plotW = this.chartW - this.padL - this.padR;
    const plotH = this.chartH - this.padT - this.padB;
    const n     = slots.length;
    const slotW = plotW / n;
    const barW  = Math.min(slotW * 0.65, 40);

    const bars = slots.map((season, i) => {
      const data   = dataMap.get(season.id);
      const price  = data?.price ?? 0;
      const barH   = price > 0 ? (price / maxPrice) * plotH : 0;
      const x      = this.padL + i * slotW + (slotW - barW) / 2;
      const y      = this.padT + plotH - barH;
      const labelX = this.padL + i * slotW + slotW / 2;
      return {
        x, y, width: barW, height: barH,
        color:   data ? (this.positionColors[data.position] ?? '#999') : 'transparent',
        label:   this.cache.seasonName(season.id),
        labelX,
        tooltip: data ? this.formatPrice(data.price) : '',
      };
    });

    const yTicks = [
      { y: this.padT,         label: this.formatPriceShort(maxPrice) },
      { y: this.padT + plotH, label: '0' },
    ];

    // Points overlay line — only connect seasons that have data (skip gaps)
    const maxPts = Math.max(...p.seasons.map(s => +s.total_points), 1);
    const ptsLinePts = slots
      .map((season, i) => {
        const data = dataMap.get(season.id);
        if (!data) return null;
        const x = this.padL + i * slotW + slotW / 2;
        const y = this.padT + plotH - (+data.total_points / maxPts) * plotH;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
      })
      .filter((pt): pt is string => pt !== null);
    const pointsLine = ptsLinePts.length >= 2 ? ptsLinePts.join(' ') : null;

    return { bars, yTicks, pointsLine };
  });

  idCopied = signal(false);

  copyId(id: string): void {
    navigator.clipboard.writeText(id).then(() => {
      this.idCopied.set(true);
      setTimeout(() => this.idCopied.set(false), 1500);
    });
  }

  constructor() {
    this.cache.ensureSeasons();
    effect(() => {
      const entries  = this.watchlistEntries();
      const playerId = this.playerId();
      this.watchlistEntryId.set(entries.find(e => e.player_id === playerId)?.id ?? null);
    });
  }
}
