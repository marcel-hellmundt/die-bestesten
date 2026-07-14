import { Component, inject, signal, computed, TemplateRef, ViewChild, ElementRef, effect } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, forkJoin, map, of, switchMap } from 'rxjs';
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
}

@Component({
  selector: 'app-markt-player',
  standalone: false,
  templateUrl: './markt-player.component.html',
  styleUrl: './markt-player.component.scss',
})
export class MarktPlayerComponent {
  private api         = inject(ApiService);
  private bottomSheet = inject(BottomSheetService);
  private cache       = inject(DataCacheService);

  @ViewChild('filterSheet') filterSheet!: TemplateRef<any>;
  @ViewChild('tableContainer') tableContainer?: ElementRef<HTMLDivElement>;

  private readonly STORAGE_KEY = 'markt-player-filters';

  private loadFilters() {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      return raw ? JSON.parse(raw) : {};
    } catch { return {}; }
  }

  constructor() {
    this.cache.ensureLeague();
    this.cache.ensureSeasons();

    effect(() => {
      this.filteredPlayers();
      setTimeout(() => { if (this.tableContainer) this.tableContainer.nativeElement.scrollLeft = 0; }, 0);
    });

    effect(() => {
      localStorage.setItem(this.STORAGE_KEY, JSON.stringify({
        search:   this.searchQuery(),
        position: this.positionFilter(),
        club:     this.clubFilter(),
        maxPrice: this.maxPrice(),
      }));
    });
  }

  private data = toSignal(
    this.api.get<{ players: FreeAgent[] }>('player_in_season/available_players')
  );

  players = computed(() => this.data()?.players ?? []);
  loading = computed(() => this.data() === undefined);

  private _saved = this.loadFilters();
  searchQuery    = signal<string>(this._saved.search    ?? '');
  positionFilter = signal<string | null>(this._saved.position ?? null);
  clubFilter     = signal<string | null>(this._saved.club     ?? null);
  maxPrice       = signal<number | null>(this._saved.maxPrice ?? null);

  dynamicPrice(p: FreeAgent): number { return p.price + 20_000 * p.season_points; }

  maxDataPrice = computed(() => Math.max(0, ...this.players().map(p => this.dynamicPrice(p))));

  // All clubs of the active season's league division — independent of whether they
  // currently have any free-agent players, so the filter always shows the full set.
  private activeSeasonId = toSignal(
    this.api.get<any>('season/active').pipe(
      map(data => data.id as string),
      catchError(() => of(null as string | null)),
    ),
  );

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

  filteredPlayers = computed(() => {
    const q    = this.searchQuery().trim().toLowerCase();
    const pos  = this.positionFilter();
    const club = this.clubFilter();
    const max  = this.maxPrice();
    return this.players().filter(p =>
      (!q    || p.displayname.toLowerCase().includes(q)) &&
      (!pos  || p.position === pos) &&
      (!club || p.club_id === club) &&
      (max === null || this.dynamicPrice(p) <= max)
    );
  });

  hasFilters = computed(() =>
    !!this.searchQuery() || !!this.positionFilter() || !!this.clubFilter() || this.maxPrice() !== null
  );

  readonly POSITIONS = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];
  readonly POS_LABEL: Record<string, string> = {
    GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU',
  };

  photoErrors = new Set<string>();
  clubErrors  = new Set<string>();
  onPhotoError(id: string): void { this.photoErrors.add(id); }
  onClubError(id: string): void  { this.clubErrors.add(id); }

  photoUrl(p: FreeAgent): string | null {
    if (!p.photo_uploaded) return null;
    return `https://img.die-bestesten.de/player/${p.season_id}/${p.id}.png`;
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

  onPriceInput(event: Event): void {
    const val = +(event.target as HTMLInputElement).value;
    this.maxPrice.set(val >= this.maxDataPrice() ? null : val);
  }

  resetFilters(): void {
    this.searchQuery.set('');
    this.positionFilter.set(null);
    this.clubFilter.set(null);
    this.maxPrice.set(null);
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
