import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, tap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { League } from '../../core/models/league.model';

@Component({
  selector: 'app-data-league',
  standalone: false,
  templateUrl: './league.component.html',
  styleUrl: './league.component.scss',
})
export class LeagueDataComponent {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  cache = inject(DataCacheService);

  isAdmin = computed(() => this.auth.isAdmin());

  private state = toSignal(
    this.api.get<any[]>('league').pipe(
      map((data) => ({
        data: data.map(League.from),
        loading: false,
        error: null as string | null,
      })),
      startWith({ data: [] as League[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as League[], loading: false, error: 'Fehler beim Laden' })),
    ),
  );

  items = computed(() => this.state()?.data ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error = computed(() => this.state()?.error ?? null);

  searchQuery = signal('');
  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(
      (i) => i.name.toLowerCase().includes(q) || i.slug.toLowerCase().includes(q),
    );
  });

  expandedId = signal<string | null>(null);
  managersCache = signal<Record<string, any[]>>({});
  managersLoading = signal<Record<string, boolean>>({});

  toggleLeague(league: League): void {
    if (this.expandedId() === league.id) {
      this.expandedId.set(null);
      return;
    }
    this.expandedId.set(league.id);
    if (this.managersCache()[league.id]) return;

    this.managersLoading.update((s) => ({ ...s, [league.id]: true }));
    this.api
      .get<any>(`league/${league.id}`)
      .pipe(
        tap((data) => {
          this.managersCache.update((s) => ({ ...s, [league.id]: data.managers ?? [] }));
          this.managersLoading.update((s) => ({ ...s, [league.id]: false }));
        }),
        catchError(() => {
          this.managersLoading.update((s) => ({ ...s, [league.id]: false }));
          return of(null);
        }),
      )
      .subscribe();
  }

  managers(leagueId: string): any[] {
    return this.managersCache()[leagueId] ?? [];
  }

  migrateStates = signal<Record<string, 'idle' | 'loading' | 'success' | 'error'>>({});
  migrateResults = signal<Record<string, any>>({});

  migrateState(leagueId: string): 'idle' | 'loading' | 'success' | 'error' {
    return this.migrateStates()[leagueId] ?? 'idle';
  }

  skippedRatings(leagueId: string): { season_id: string; matchday_number: number; count: number }[] {
    const details = this.migrateResults()[leagueId]?.team_ratings?.skipped_details ?? [];
    return [...details].sort((a: any, b: any) => {
      const aDate = this.cache.seasons().find((s) => s.id === a.season_id)?.start_date ?? '';
      const bDate = this.cache.seasons().find((s) => s.id === b.season_id)?.start_date ?? '';
      if (bDate !== aDate) return bDate.localeCompare(aDate);
      return Number(b.matchday_number) - Number(a.matchday_number);
    });
  }

  migrate(league: League): void {
    this.migrateStates.update((s) => ({ ...s, [league.id]: 'loading' }));
    this.cache.ensureSeasons();
    this.api.post<any>('league/migrate', { league_id: league.id }).subscribe({
      next: (res) => {
        this.migrateStates.update((s) => ({ ...s, [league.id]: 'success' }));
        this.migrateResults.update((s) => ({ ...s, [league.id]: res }));
      },
      error: () => this.migrateStates.update((s) => ({ ...s, [league.id]: 'error' })),
    });
  }
}
