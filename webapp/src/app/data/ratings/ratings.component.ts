import { Component, computed, effect, inject, signal } from '@angular/core';
import { catchError, forkJoin, map, of, startWith, switchMap } from 'rxjs';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { Matchday } from '../../core/models/matchday.model';
import { PlayerRating } from '../../core/models/player-rating.model';

interface Club {
  id: string;
  name: string;
  short_name: string | null;
  logo_uploaded: boolean;
  country_id: string;
}

interface ClubInSeason {
  club_id: string;
  division_id: string;
}

interface Division {
  id: string;
  level: number;
  country_id: string;
  name: string;
}

@Component({
  selector: 'app-data-ratings',
  standalone: false,
  templateUrl: './ratings.component.html',
  styleUrl: './ratings.component.scss'
})
export class RatingsDataComponent {
  private api   = inject(ApiService);
  private auth  = inject(AuthService);
  private cache = inject(DataCacheService);

  isAdmin       = computed(() => this.auth.isAdmin());
  isMaintainer  = computed(() => this.auth.isMaintainer());

  // ── Active season ──────────────────────────────────────────────
  private activeSeasonState = toSignal(
    this.api.get<any>('season/active').pipe(
      map(data => ({ id: data.id as string, loading: false })),
      startWith({ id: null as string | null, loading: true }),
      catchError(() => of({ id: null as string | null, loading: false }))
    )
  );
  activeSeasonId = computed(() => this.activeSeasonState()?.id ?? null);

  // ── Page data (matchdays + clubs) for active season ───────────
  private pageData = toSignal(
    toObservable(this.activeSeasonId).pipe(
      switchMap(seasonId => {
        if (!seasonId) return of(null);
        return forkJoin({
          matchdays:     this.api.get<any[]>(`matchday?season_id=${seasonId}`),
          clubsInSeason: this.api.get<any[]>(`club_in_season?season_id=${seasonId}`),
          clubs:         this.api.get<any[]>('club'),
          divisions:     this.api.get<any[]>('division'),
        }).pipe(
          map(({ matchdays, clubsInSeason, clubs, divisions }) => ({
            matchdays:     matchdays.map(Matchday.from),
            clubsInSeason: clubsInSeason as ClubInSeason[],
            clubs:         clubs as Club[],
            divisions:     divisions as Division[],
            loading: false,
          })),
          startWith({ matchdays: [] as Matchday[], clubsInSeason: [] as ClubInSeason[], clubs: [] as Club[], divisions: [] as Division[], loading: true }),
        );
      })
    )
  );

  pageLoading = computed(() => this.pageData()?.loading ?? true);

  // Previous season club_in_season — for position-based sorting
  private prevSeasonId = computed(() => this.cache.seasons()[1]?.id ?? null);

  private prevSeasonEntries = toSignal(
    toObservable(this.prevSeasonId).pipe(
      switchMap(id => {
        if (!id) return of([] as any[]);
        return this.api.get<any[]>(`club_in_season?season_id=${id}`).pipe(
          catchError(() => of([] as any[]))
        );
      })
    ),
    { initialValue: [] as any[] }
  );

  // Sorted ASC (lowest number = oldest = first uncompleted)
  matchdays = computed(() =>
    [...(this.pageData()?.matchdays ?? [])].sort((a, b) => a.number - b.number)
  );

  bundesligaClubs = computed((): Club[] => {
    const pd = this.pageData();
    if (!pd || pd.loading) return [];

    const bundesligaDivIds = new Set(
      pd.divisions
        .filter(d => Number(d.level) === 1 && d.country_id?.toLowerCase() === 'de')
        .map(d => d.id)
    );
    const bundesligaClubIds = new Set(
      pd.clubsInSeason
        .filter((cis: ClubInSeason) => bundesligaDivIds.has(cis.division_id))
        .map((cis: ClubInSeason) => cis.club_id)
    );
    const positionMap = new Map<string, number>(
      this.prevSeasonEntries()
        .filter((e: any) => bundesligaDivIds.has(e.division_id) && e.position != null)
        .map((e: any) => [e.club_id as string, e.position as number])
    );
    return pd.clubs
      .filter(c => bundesligaClubIds.has(c.id))
      .sort((a, b) => (positionMap.get(a.id) ?? 999) - (positionMap.get(b.id) ?? 999));
  });

  // Auto-select: first uncompleted matchday (lowest number), fallback to last
  private autoSelected = computed(() => {
    const mds = this.matchdays();
    if (mds.length === 0) return null;
    return mds.find(m => !m.completed) ?? mds[mds.length - 1];
  });

  selectedMatchday = signal<Matchday | null>(null);
  selectedClubId   = signal<string | null>(null);

  // Apply auto-selection once data is available
  constructor() {
    this.cache.ensureSeasons();
    effect(() => {
      const md = this.autoSelected();
      if (md && !this.selectedMatchday()) this.selectedMatchday.set(md);
    });
  }

  selectMatchday(md: Matchday): void {
    this.selectedMatchday.set(md);
    this.selectedClubId.set(null);
    this.ratings.set([]);
    this.initWarnings.set([]);
    this.beforeKickoff.set(false);
    this.ratingsState.set('idle');
    this.bulkInput.set('');
    this.bulkResult.set(null);
  }

  // ── Ratings state ──────────────────────────────────────────────
  ratingsState      = signal<'idle' | 'loading' | 'ready' | 'error'>('idle');
  beforeKickoff     = signal(false);
  ratings           = signal<PlayerRating[]>([]);
  initWarnings      = signal<string[]>([]);
  initCreatedNames  = signal<string[]>([]);

  selectClub(clubId: string): void {
    if (this.selectedClubId() === clubId) return;
    this.selectedClubId.set(clubId);
    this.initWarnings.set([]);
    this.initCreatedNames.set([]);
    this.beforeKickoff.set(false);
    this.bulkInput.set('');
    this.bulkResult.set(null);

    const md = this.selectedMatchday();
    if (!md) return;

    if (md.completed || !this.isMaintainer()) {
      // Read-only: just load existing ratings
      this.loadRatings(md.id, clubId);
    } else {
      // Maintainer+: init empty ratings if needed, then load
      this.ratingsState.set('loading');
      this.api.post<any>('player_rating/init', { matchday_id: md.id, club_id: clubId }).subscribe({
        next: (res) => {
          if (res.existing?.length > 0) {
            this.initWarnings.set(res.existing.map((e: any) => e.displayname));
          }
          if (res.created?.length > 0) {
            this.initCreatedNames.set(res.created.map((c: any) => c.displayname));
          }
          this.loadRatings(md.id, clubId);
        },
        error: (err) => {
          if (err?.error?.message === 'Spieltag hat noch nicht begonnen') {
            this.beforeKickoff.set(true);
            this.ratingsState.set('idle');
          } else {
            this.ratingsState.set('error');
          }
        },
      });
    }
  }

  private loadRatings(matchdayId: string, clubId: string): void {
    this.ratingsState.set('loading');
    this.api.get<any[]>(`player_rating?matchday_id=${matchdayId}&club_id=${clubId}`).subscribe({
      next: (data) => {
        this.ratings.set(data.map(PlayerRating.from));
        this.ratingsState.set('ready');
      },
      error: () => this.ratingsState.set('error'),
    });
  }

  // ── Mark completed ─────────────────────────────────────────────
  completeState = signal<'idle' | 'loading' | 'done'>('idle');

  markCompleted(): void {
    const md = this.selectedMatchday();
    if (!md) return;
    this.completeState.set('loading');
    this.api.patch<any>(`matchday/${md.id}`, { completed: true }).subscribe({
      next: () => {
        // Update local matchday object
        const updated = new Matchday(md.id, md.season_id, md.number, md.start_date, md.kickoff_date, true);
        this.selectedMatchday.set(updated);
        this.completeState.set('done');
      },
      error: () => this.completeState.set('idle'),
    });
  }

  // ── Helpers ────────────────────────────────────────────────────
  matchdayById(id: string): Matchday | null {
    return this.matchdays().find(m => m.id === id) ?? null;
  }

  clubById(id: string | null): Club | null {
    if (!id) return null;
    return this.bundesligaClubs().find(c => c.id === id) ?? null;
  }

  logoUrl(club: Club): string {
    return club.logo_uploaded
      ? `https://img.die-bestesten.de/img/club/${club.id}.png`
      : 'img/placeholders/club.png';
  }

  private static readonly POSITION_ORDER: Record<string, number> = {
    GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3
  };
  private static readonly POSITION_LABEL: Record<string, string> = {
    GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU'
  };

  posLabel(pos: string | null): string {
    return pos ? (RatingsDataComponent.POSITION_LABEL[pos] ?? pos) : '';
  }

  private byPositionOnly = (a: PlayerRating, b: PlayerRating) => {
    const qa = RatingsDataComponent.POSITION_ORDER[a.position ?? ''] ?? 9;
    const qb = RatingsDataComponent.POSITION_ORDER[b.position ?? ''] ?? 9;
    if (qa !== qb) return qa - qb;
    return (b.price ?? 0) - (a.price ?? 0);
  };

  private byBench = (a: PlayerRating, b: PlayerRating) => {
    if (b.starting_count !== a.starting_count) return b.starting_count - a.starting_count;
    const qa = RatingsDataComponent.POSITION_ORDER[a.position ?? ''] ?? 9;
    const qb = RatingsDataComponent.POSITION_ORDER[b.position ?? ''] ?? 9;
    if (qa !== qb) return qa - qb;
    return (b.price ?? 0) - (a.price ?? 0);
  };

  startingRatings   = computed(() => [...this.ratings()].filter(r => r.participation === 'starting').sort(this.byPositionOnly));
  substituteRatings = computed(() => [...this.ratings()].filter(r => r.participation === 'substitute').sort(this.byPositionOnly));
  benchRatings      = computed(() => [...this.ratings()].filter(r => !r.participation).sort(this.byBench));

  participationError = signal<string | null>(null);

  incrementGoals(ratingId: string, current: number): void {
    this.patchStat(ratingId, 'goals', current + 1);
  }

  decrementGoals(ratingId: string, current: number): void {
    if (current <= 0) return;
    this.patchStat(ratingId, 'goals', current - 1);
  }

  incrementAssists(ratingId: string, current: number): void {
    this.patchStat(ratingId, 'assists', current + 1);
  }

  decrementAssists(ratingId: string, current: number): void {
    if (current <= 0) return;
    this.patchStat(ratingId, 'assists', current - 1);
  }

  resetRating(ratingId: string): void {
    const reset = { participation: null, grade: null, goals: 0, assists: 0, clean_sheet: 0, sds: 0, red_card: 0, yellow_red_card: 0 };
    this.api.patch<any>(`player_rating/${ratingId}`, reset).subscribe({
      next: (res) => this.ratings.update(list =>
        list.map(r => r.id === ratingId ? PlayerRating.from({ ...res.rating, starting_count: r.starting_count }) : r)
      ),
    });
  }

  private patchStat(ratingId: string, field: 'goals' | 'assists', value: number): void {
    this.api.patch<any>(`player_rating/${ratingId}`, { [field]: value }).subscribe({
      next: (res) => {
        this.ratings.update(list =>
          list.map(r => r.id === ratingId ? PlayerRating.from({ ...res.rating, starting_count: r.starting_count }) : r)
        );
      },
    });
  }

  setParticipation(ratingId: string, value: 'starting' | 'substitute'): void {
    const limit = value === 'starting' ? 11 : 5;
    const current = this.ratings().filter(r => r.participation === value && r.id !== ratingId).length;
    if (current >= limit) {
      this.participationError.set(
        value === 'starting'
          ? `Maximal 11 Startspieler erlaubt`
          : `Maximal 5 Einwechslungen erlaubt`
      );
      return;
    }
    this.participationError.set(null);
    this.api.patch<any>(`player_rating/${ratingId}`, { participation: value }).subscribe({
      next: (res) => {
        this.ratings.update(list =>
          list.map(r => r.id === ratingId ? PlayerRating.from({ ...res.rating, starting_count: r.starting_count }) : r)
        );
      },
    });
  }

  // ── Bulk lineup import ────────────────────────────────────────────
  bulkInput  = signal('');
  bulkResult = signal<{ matched: string[], unmatched: string[] } | null>(null);

  parseBulkLineup(): void {
    const tokens = this.extractNames(this.bulkInput());
    if (!tokens.length) return;

    const matched:   string[] = [];
    const unmatched: string[] = [];

    const alreadyStarting = this.ratings().filter(r => r.participation === 'starting').length;
    let newlyAssigned = 0;

    for (const token of tokens) {
      const player = this.findBestMatch(token);
      if (!player) {
        unmatched.push(token);
        continue;
      }
      // Already starting — silently skip
      if (player.participation === 'starting') continue;
      if (alreadyStarting + newlyAssigned >= 11) {
        unmatched.push(token);
        continue;
      }
      newlyAssigned++;
      matched.push(player.displayname);
      this.api.patch<any>(`player_rating/${player.id}`, { participation: 'starting' }).subscribe({
        next: (res) => this.ratings.update(list =>
          list.map(r => r.id === player.id ? PlayerRating.from({ ...res.rating, starting_count: r.starting_count }) : r)
        ),
      });
    }

    this.bulkResult.set({ matched, unmatched });
    this.bulkInput.set('');
  }

  private extractNames(text: string): string[] {
    const lines = text.split(/\n+/).map(l => l.trim()).filter(l => l.length > 0);

    // Find the Startelf line; fall back to first non-Bank line
    const startelfLine =
      lines.find(l => /^startelf/i.test(l)) ??
      lines.find(l => !/^bank/i.test(l)) ??
      '';

    // Strip label prefix ("Startelf: ") if present
    const cleaned = startelfLine.replace(/^[^:]*:\s*/, '').trim();

    return cleaned
      .split(/\s*[–—,]\s*/)
      .map(s => s.trim())
      .filter(s => s.length > 1);
  }

  private findBestMatch(token: string): PlayerRating | null {
    const norm = this.normalize(token);
    const pool = this.ratings();

    const exact = pool.find(r => this.normalize(r.last_name ?? '') === norm
                               || this.normalize(r.displayname) === norm);
    if (exact) return exact;

    const partial = pool.find(r => {
      const ln = this.normalize(r.last_name ?? '');
      return ln && (ln.includes(norm) || norm.includes(ln));
    });
    if (partial) return partial;

    const words = norm.split(/\s+/).filter(w => w.length > 2);
    return pool.find(r => {
      const dn = this.normalize(r.displayname).split(/\s+/);
      return words.some(w => dn.some(dw => dw === w || dw.includes(w) || w.includes(dw)));
    }) ?? null;
  }

  private normalize(s: string): string {
    return (s ?? '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
  }

  gradeVar(grade: number | null): string {
    if (!grade) return 'var(--grade-unset)';
    const key = Math.round(grade * 2) * 5; // 1.0→10, 1.5→15, …, 6.0→60
    return `var(--grade-${key})`;
  }

  range(n: number | null): number[] {
    if (!n || n <= 0) return [];
    return Array.from({ length: n }, (_, i) => i);
  }

}
