import { Component, inject, signal, computed, TemplateRef, ViewChild, ElementRef, effect } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, forkJoin, map, of, startWith, Subject, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { DataCacheService } from '../../core/data-cache.service';

interface FreeAgent {
  id: string;
  displayname: string;
  position: string;
  price: number;
  season_points: number;
  photo_uploaded: boolean;
  club_id: string;
  club_name: string;
  club_short_name: string | null;
  club_logo_uploaded: boolean;
  prev_club_position: number | null;
  season_id: string;
  current_team_id: string | null;
  current_team_name: string | null;
  current_team_season_id: string | null;
  new_on_market: boolean;
}

@Component({
  selector: 'app-markt-player',
  standalone: false,
  templateUrl: './markt-player.component.html',
  styleUrl: './markt-player.component.scss',
})
export class MarktPlayerComponent {
  private api    = inject(ApiService);
  bottomSheet    = inject(BottomSheetService);
  cache          = inject(DataCacheService);

  @ViewChild('filterSheet') filterSheet!: TemplateRef<any>;
  @ViewChild('offerSheet') offerSheet!: TemplateRef<any>;
  @ViewChild('tableContainer') tableContainer?: ElementRef<HTMLDivElement>;

  // Gespeichert pro Liga (leagueId), da Vereins-/Positionsfilter nur für die Division der
  // jeweiligen Liga sinnvoll sind — ein flacher, ligaübergreifender Speicher führte dazu, dass
  // ein in Liga A gewählter Verein beim Wechsel zu Liga B (andere Division) keinen Spieler mehr
  // matcht und die Liste dadurch leer erscheint.
  private readonly STORAGE_KEY = 'markt-player-filters';

  // Vor dem Liga-Umbau lagen die Filterfelder direkt auf der Wurzel des gespeicherten Objekts
  // statt unter einem leagueId-Schlüssel. Bestehende localStorage-Einträge räumen wir beim
  // nächsten Zugriff einmalig auf, statt sie als Datenleiche neben den Liga-Einträgen liegen
  // zu lassen.
  private static readonly LEGACY_ROOT_KEYS = ['search', 'position', 'club', 'maxPrice', 'showAll', 'newOnly'];

  private readStore(): Record<string, any> {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      if (!raw) return {};
      const all = JSON.parse(raw);
      const hasLegacyKeys = MarktPlayerComponent.LEGACY_ROOT_KEYS.some(k => k in all);
      if (hasLegacyKeys) {
        for (const k of MarktPlayerComponent.LEGACY_ROOT_KEYS) delete all[k];
        localStorage.setItem(this.STORAGE_KEY, JSON.stringify(all));
      }
      return all;
    } catch { return {}; }
  }

  private loadFiltersFor(leagueId: string): any {
    return this.readStore()[leagueId] ?? {};
  }

  private saveFiltersFor(leagueId: string, filters: unknown): void {
    try {
      const all = this.readStore();
      all[leagueId] = filters;
      localStorage.setItem(this.STORAGE_KEY, JSON.stringify(all));
    } catch { /* ignore */ }
  }

  constructor() {
    this.cache.ensureLeague();
    this.cache.ensureSeasons();
    this.cache.ensureMyTeam();

    // leagueId ist beim Start noch nicht bekannt (async via /league/mine) — sobald es vorliegt,
    // die für DIESE Liga gespeicherten Filter einmalig übernehmen.
    effect(() => {
      const leagueId = this.cache.leagueId();
      if (!leagueId) return;
      const saved = this.loadFiltersFor(leagueId);
      this.searchQuery.set(saved.search ?? '');
      this.positionFilter.set(saved.position ?? null);
      this.clubFilter.set(saved.club ?? null);
      this.maxPrice.set(saved.maxPrice ?? null);
      this.showAllPlayers.set(saved.showAll ?? false);
      this.newOnMarketOnly.set(saved.newOnly ?? false);
    });

    effect(() => {
      this.filteredPlayers();
      setTimeout(() => { if (this.tableContainer) this.tableContainer.nativeElement.scrollLeft = 0; }, 0);
    });

    effect(() => {
      const leagueId = this.cache.leagueId();
      if (!leagueId) return;
      this.saveFiltersFor(leagueId, {
        search:   this.searchQuery(),
        position: this.positionFilter(),
        club:     this.clubFilter(),
        maxPrice: this.maxPrice(),
        showAll:  this.showAllPlayers(),
        newOnly:  this.newOnMarketOnly(),
      });
    });
  }

  showAllPlayers  = signal<boolean>(false);
  newOnMarketOnly = signal<boolean>(false);

  private data = toSignal(
    toObservable(this.showAllPlayers).pipe(
      switchMap(showAll => this.api.get<{ players: FreeAgent[] }>(
        `player_in_season/available_players${showAll ? '?include_all=1' : ''}`
      )),
    ),
  );

  players = computed(() => this.data()?.players ?? []);
  loading = computed(() => this.data() === undefined);

  // All clubs of the active season's league division — independent of whether they
  // currently have any free-agent players, so the filter always shows the full set.
  private activeSeasonId = toSignal(
    this.api.get<any>('season/active').pipe(
      map(data => data.id as string),
      catchError(() => of(null as string | null)),
    ),
  );

  // ── Remaining budget (echtes Budget - offene Gebote) ─────────────────────────
  private refreshOffers$ = new Subject<void>();

  private budgetData = toSignal(
    toObservable(this.cache.myTeamId).pipe(
      switchMap(id => {
        if (!id) return of(null);
        return this.refreshOffers$.pipe(
          startWith(null),
          switchMap(() => this.api.get<{ budget: number }>(`transaction?team_id=${id}`).pipe(catchError(() => of(null)))),
        );
      }),
    ),
  );

  private offersData = toSignal(
    toObservable(this.cache.myTeamId).pipe(
      switchMap(id => {
        if (!id) return of(null);
        return this.refreshOffers$.pipe(
          startWith(null),
          switchMap(() => this.api.get<{ pending_sum: number }>(`offer?team_id=${id}`).pipe(catchError(() => of(null)))),
        );
      }),
    ),
  );

  remainingBudget = computed(() => {
    const budget = this.budgetData()?.budget;
    if (budget === undefined || budget === null) return null;
    const pending = this.offersData()?.pending_sum ?? 0;
    return budget - pending;
  });

  // ── Offenes Transferfenster der aktiven Saison ────────────────────────────
  openWindow = toSignal(
    toObservable(this.activeSeasonId).pipe(
      switchMap(seasonId => {
        if (!seasonId) return of(null);
        return this.api.get<any[]>(`transferwindow?season_id=${seasonId}`).pipe(
          map(windows => {
            const now = new Date();
            return windows.find(w => new Date(w.start_date) <= now && new Date(w.end_date) > now) ?? null;
          }),
          catchError(() => of(null)),
        );
      }),
    ),
    { initialValue: null as any },
  );

  // ── Beobachtungsliste ──────────────────────────────────────────────────────
  private refreshWatchlist$ = new Subject<void>();

  private watchlistEntries = toSignal(
    toObservable(this.cache.myTeamId).pipe(
      switchMap(teamId => {
        if (!teamId) return of([] as { id: string; player_id: string }[]);
        return this.refreshWatchlist$.pipe(
          startWith(null),
          switchMap(() =>
            this.api.get<{ id: string; player_id: string }[]>(`watchlist?team_id=${teamId}`).pipe(
              catchError(() => of([] as { id: string; player_id: string }[])),
            )
          ),
        );
      }),
    ),
    { initialValue: [] as { id: string; player_id: string }[] },
  );

  private watchlistMap = computed(() => new Map(this.watchlistEntries().map(e => [e.player_id, e.id])));

  isWatched(p: FreeAgent): boolean {
    return this.watchlistMap().has(p.id);
  }

  watchToggling = new Set<string>();

  toggleWatch(p: FreeAgent): void {
    const teamId = this.cache.myTeamId();
    if (!teamId || this.watchToggling.has(p.id)) return;
    this.watchToggling.add(p.id);
    const entryId = this.watchlistMap().get(p.id);
    const done = () => { this.watchToggling.delete(p.id); this.refreshWatchlist$.next(); };
    const fail = () => { this.watchToggling.delete(p.id); };
    if (entryId) {
      this.api.delete<null>(`watchlist/${entryId}`, { team_id: teamId }).subscribe({ next: done, error: fail });
    } else {
      this.api.post<{ id: string }>('watchlist', { team_id: teamId, player_id: p.id }).subscribe({ next: done, error: fail });
    }
  }

  // ── Gebot abgeben (Quick-Action) ───────────────────────────────────────────
  canBid(p: FreeAgent): boolean {
    return !p.current_team_id && !!this.openWindow() && !!this.cache.myTeamId();
  }

  selectedOfferPlayer = signal<FreeAgent | null>(null);
  offerSubmitting     = signal(false);
  offerError          = signal<string | null>(null);
  offerSuccess        = signal(false);

  // Digit spinner — 4 controllable digits (10M / 1M / 100K / 10K), granularity 10.000,
  // gleiches Eingabemuster wie im "Gebot abgeben"-Dialog der Spielerdetailseite.
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

  offerMarketValue = computed(() => {
    const p = this.selectedOfferPlayer();
    return p ? this.dynamicPrice(p) : 0;
  });

  offerPercentage = computed(() => {
    const mv = this.offerMarketValue();
    if (!mv) return 0;
    return Math.round(this.offerValue() / mv * 100);
  });

  sliderPct = computed(() => Math.min(200, Math.max(100, this.offerPercentage())));

  isValidOffer = computed(() => {
    const mv     = this.offerMarketValue();
    const budget = this.remainingBudget() ?? 0;
    return mv > 0 && this.offerValue() >= mv && this.offerValue() <= budget;
  });

  private setDigitsFromValue(v: number): void {
    const s = String(Math.max(0, Math.floor(v / 10_000) * 10_000)).padStart(8, '0');
    this.digitE10000000.set(+s[s.length - 8] || 0);
    this.digitE1000000.set( +s[s.length - 7] || 0);
    this.digitE100000.set(  +s[s.length - 6] || 0);
    this.digitE10000.set(   +s[s.length - 5] || 0);
  }

  openOffer(p: FreeAgent): void {
    if (!this.canBid(p)) return;
    this.selectedOfferPlayer.set(p);
    this.offerSuccess.set(false);
    this.offerError.set(null);
    this.setDigitsFromValue(this.dynamicPrice(p));
    this.bottomSheet.open(this.offerSheet, { title: 'Gebot abgeben' });
  }

  closeOfferSheet(): void {
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
    const raw = Math.round(pct / 100 * this.offerMarketValue() / 10_000) * 10_000;
    this.setDigitsFromValue(Math.min(raw, this.remainingBudget() ?? 0));
  }

  onAllIn(): void {
    this.setDigitsFromValue(this.remainingBudget() ?? 0);
  }

  submitOffer(): void {
    const teamId = this.cache.myTeamId();
    const win    = this.openWindow();
    const p      = this.selectedOfferPlayer();
    if (!teamId || !win || !p || !this.isValidOffer()) return;
    this.offerSubmitting.set(true);
    this.offerError.set(null);
    this.api.post<any>('offer', {
      team_id: teamId, player_id: p.id,
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

  searchQuery    = signal<string>('');
  positionFilter = signal<string | null>(null);
  clubFilter     = signal<string | null>(null);
  maxPrice       = signal<number | null>(null);

  dynamicPrice(p: FreeAgent): number { return p.price + 20_000 * p.season_points; }

  maxDataPrice = computed(() => Math.max(0, ...this.players().map(p => this.dynamicPrice(p))));

  private clubsPageData = toSignal(
    toObservable(this.activeSeasonId).pipe(
      switchMap(seasonId => {
        if (!seasonId) return of(null);
        return forkJoin({
          clubsInSeason: this.api.get<any[]>(`club_in_season?season_id=${seasonId}`),
          clubs:         this.api.get<any[]>('club'),
        }).pipe(catchError(() => of(null)));
      }),
    ),
  );

  // seasons() is DESC by start_date → [1] = previous season, used to sort the club
  // filter by last season's standings (this season's position is usually still unset).
  private prevSeasonId = computed(() => this.cache.seasons()[1]?.id ?? null);

  private prevSeasonEntries = toSignal(
    toObservable(this.prevSeasonId).pipe(
      switchMap(id => {
        if (!id) return of([] as any[]);
        return this.api.get<any[]>(`club_in_season?season_id=${id}`).pipe(catchError(() => of([] as any[])));
      }),
    ),
    { initialValue: [] as any[] },
  );

  clubs = computed(() => {
    const pd = this.clubsPageData();
    const divisionId = this.cache.leagueDivisionId();
    if (!pd || !divisionId) return [];

    const clubMap = new Map(pd.clubs.map((c: any) => [c.id, c]));
    const prevPositionMap = new Map<string, number>(
      this.prevSeasonEntries()
        .filter((e: any) => e.division_id === divisionId && e.position != null)
        .map((e: any) => [e.club_id as string, e.position as number]),
    );

    return pd.clubsInSeason
      .filter((e: any) => e.division_id === divisionId)
      .map((e: any) => {
        const c = clubMap.get(e.club_id);
        return c ? { id: c.id, name: c.name, short_name: c.short_name, logo_uploaded: c.logo_uploaded } : null;
      })
      .filter((c): c is { id: string; name: string; short_name: string | null; logo_uploaded: boolean } => c !== null)
      .sort((a, b) => (prevPositionMap.get(a.id) ?? 999) - (prevPositionMap.get(b.id) ?? 999));
  });

  sortCol = signal<'price' | 'points'>('points');
  sortDir = signal<'asc' | 'desc'>('desc');

  sort(col: 'price' | 'points'): void {
    if (this.sortCol() === col) {
      this.sortDir.update(d => d === 'asc' ? 'desc' : 'asc');
    } else {
      this.sortCol.set(col);
      this.sortDir.set('desc');
    }
  }

  filteredPlayers = computed(() => {
    const q       = this.searchQuery().trim().toLowerCase();
    const pos     = this.positionFilter();
    const club    = this.clubFilter();
    const max     = this.maxPrice();
    const newOnly = this.newOnMarketOnly();
    const col     = this.sortCol();
    const dir     = this.sortDir();

    const filtered = this.players().filter(p =>
      (!q       || p.displayname.toLowerCase().includes(q)) &&
      (!pos     || p.position === pos) &&
      (!club    || p.club_id === club) &&
      (max === null || this.dynamicPrice(p) <= max) &&
      (!newOnly || p.new_on_market)
    );

    return [...filtered].sort((a, b) => {
      const cmp = col === 'price'
        ? this.dynamicPrice(a) - this.dynamicPrice(b)
        : a.season_points - b.season_points;
      return dir === 'asc' ? cmp : -cmp;
    });
  });

  hasFilters = computed(() =>
    !!this.searchQuery() || !!this.positionFilter() || !!this.clubFilter() || this.maxPrice() !== null
    || this.newOnMarketOnly()
  );

  columnCount = computed(() => (this.showAllPlayers() ? 6 : 5) + (this.cache.myTeamId() ? 1 : 0));

  readonly POSITIONS = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];
  readonly POS_LABEL: Record<string, string> = {
    GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU',
  };

  photoErrors = new Set<string>();
  clubErrors  = new Set<string>();
  teamErrors  = new Set<string>();
  onPhotoError(id: string): void { this.photoErrors.add(id); }
  onClubError(id: string): void  { this.clubErrors.add(id); }
  onTeamError(id: string): void  { this.teamErrors.add(id); }

  photoUrl(p: FreeAgent): string | null {
    if (!p.photo_uploaded) return null;
    return `https://img.die-bestesten.de/player/${p.season_id}/${p.id}.png`;
  }

  teamLogoUrl(p: FreeAgent): string | null {
    if (!p.current_team_id || !p.current_team_season_id) return null;
    return `https://img.die-bestesten.de/team/${p.current_team_season_id}/${p.current_team_id}.png`;
  }

  clubLogoUrl(p: FreeAgent): string | null {
    if (!p.club_logo_uploaded) return null;
    return `https://img.die-bestesten.de/club/${p.club_id}.png`;
  }

  togglePosition(pos: string): void {
    this.positionFilter.set(this.positionFilter() === pos ? null : pos);
  }

  toggleClub(id: string): void {
    this.clubFilter.set(this.clubFilter() === id ? null : id);
  }

  setShowAllPlayers(checked: boolean): void {
    this.showAllPlayers.set(checked);
  }

  setNewOnMarketOnly(checked: boolean): void {
    this.newOnMarketOnly.set(checked);
  }

  onPriceInput(event: Event): void {
    const val = +(event.target as HTMLInputElement).value;
    this.maxPrice.set(val >= this.maxDataPrice() ? null : val);
  }

  resetFilters(): void {
    this.searchQuery.set('');
    this.positionFilter.set(null);
    this.clubFilter.set(null);
    this.maxPrice.set(null);
    this.newOnMarketOnly.set(false);
  }

  openFilter(): void {
    this.bottomSheet.open(this.filterSheet, { title: 'Filtern' });
  }

  formatPrice(v: number): string {
    return v.toLocaleString('de-DE') + ' €';
  }

  formatPriceShort(v: number): string {
    if (v >= 1_000_000) return (v / 1_000_000).toFixed(1).replace('.', ',') + ' Mio €';
    if (v >= 1_000)     return (v / 1_000).toFixed(0) + ' T €';
    return v + ' €';
  }
}
