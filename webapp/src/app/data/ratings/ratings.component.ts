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

  isAdmin = computed(() => this.auth.isAdmin());

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
    this.ratingsState.set('idle');
  }

  // ── Ratings state ──────────────────────────────────────────────
  ratingsState = signal<'idle' | 'loading' | 'ready' | 'error'>('idle');
  ratings      = signal<PlayerRating[]>([]);
  initWarnings = signal<string[]>([]);
  initCreated  = signal<number>(0);

  selectClub(clubId: string): void {
    if (this.selectedClubId() === clubId) return;
    this.selectedClubId.set(clubId);
    this.initWarnings.set([]);
    this.initCreated.set(0);

    const md = this.selectedMatchday();
    if (!md) return;

    if (md.completed) {
      // Read-only: just load existing ratings
      this.loadRatings(md.id, clubId);
    } else {
      // Init empty ratings, then load
      this.ratingsState.set('loading');
      this.api.post<any>('player_rating/init', { matchday_id: md.id, club_id: clubId }).subscribe({
        next: (res) => {
          if (res.existing?.length > 0) {
            this.initWarnings.set(res.existing.map((e: any) => e.displayname));
          }
          if (res.created > 0) {
            this.initCreated.set(res.created);
          }
          this.loadRatings(md.id, clubId);
        },
        error: () => {
          this.ratingsState.set('error');
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
