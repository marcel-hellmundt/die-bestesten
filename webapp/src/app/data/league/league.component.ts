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

  expandedId   = signal<string | null>(null);
  teamsCache   = signal<Record<string, any[]>>({});
  teamsLoading = signal<Record<string, boolean>>({});

  toggleLeague(league: League): void {
    if (this.expandedId() === league.id) {
      this.expandedId.set(null);
      return;
    }
    this.expandedId.set(league.id);
    if (this.teamsCache()[league.id]) return;

    this.teamsLoading.update((s) => ({ ...s, [league.id]: true }));
    this.cache.ensureSeasons();
    this.api
      .get<any>(`league/${league.id}`)
      .pipe(
        tap((data) => {
          this.teamsCache.update((s) => ({ ...s, [league.id]: data.teams ?? [] }));
          this.teamsLoading.update((s) => ({ ...s, [league.id]: false }));
        }),
        catchError(() => {
          this.teamsLoading.update((s) => ({ ...s, [league.id]: false }));
          return of(null);
        }),
      )
      .subscribe();
  }

  teams(leagueId: string): any[] {
    return this.teamsCache()[leagueId] ?? [];
  }

  migrateStates = signal<Record<string, 'idle' | 'loading' | 'success' | 'error'>>({});
  migrateResults = signal<Record<string, any>>({});

  validateStates  = signal<Record<string, 'idle' | 'loading' | 'done' | 'error'>>({});
  validateResults = signal<Record<string, any>>({});

  migrateState(leagueId: string): 'idle' | 'loading' | 'success' | 'error' {
    return this.migrateStates()[leagueId] ?? 'idle';
  }

  validateState(leagueId: string): 'idle' | 'loading' | 'done' | 'error' {
    return this.validateStates()[leagueId] ?? 'idle';
  }

  validateResult(leagueId: string): any {
    return this.validateResults()[leagueId] ?? null;
  }

  groupedMismatches(mismatches: any[]): { seasonId: string; matchdays: { matchdayNumber: number; items: any[] }[] }[] {
    const seasonMap = new Map<string, Map<number, any[]>>();
    for (const mm of mismatches) {
      if (!seasonMap.has(mm.season_id)) seasonMap.set(mm.season_id, new Map());
      const mdMap = seasonMap.get(mm.season_id)!;
      if (!mdMap.has(mm.matchday_number)) mdMap.set(mm.matchday_number, []);
      mdMap.get(mm.matchday_number)!.push(mm);
    }
    const seasons = this.cache.seasons();
    return [...seasonMap.entries()]
      .sort((a, b) => {
        const aDate = seasons.find(s => s.id === a[0])?.start_date ?? '';
        const bDate = seasons.find(s => s.id === b[0])?.start_date ?? '';
        return bDate.localeCompare(aDate);
      })
      .map(([seasonId, mdMap]) => ({
        seasonId,
        matchdays: [...mdMap.entries()]
          .sort((a, b) => b[0] - a[0])
          .map(([matchdayNumber, items]) => ({ matchdayNumber, items })),
      }));
  }

  fixingState = signal<Record<string, boolean>>({});

  isFixing(leagueId: string, mm: any, field: string): boolean {
    return this.fixingState()[`${leagueId}:${mm.team_id}:${mm.matchday_id}:${field}`] ?? false;
  }

  fixField(leagueId: string, mm: any, field: string, value: number): void {
    const key = `${leagueId}:${mm.team_id}:${mm.matchday_id}:${field}`;
    if (this.fixingState()[key]) return;
    this.fixingState.update(s => ({ ...s, [key]: true }));
    this.api.post<any>('league/fix_rating', { league_id: leagueId, team_id: mm.team_id, matchday_id: mm.matchday_id, field, value }).subscribe({
      next: () => {
        this.validateResults.update(results => {
          const vr = results[leagueId];
          if (!vr) return results;
          const newMismatches = vr.mismatches
            .map((m: any) => {
              if (m.team_id !== mm.team_id || m.matchday_id !== mm.matchday_id) return m;
              const newFields = { ...m.fields };
              delete newFields[field];
              return { ...m, fields: newFields };
            })
            .filter((m: any) => Object.keys(m.fields).length > 0);
          return { ...results, [leagueId]: { ...vr, mismatches: newMismatches } };
        });
        this.fixingState.update(s => { const n = { ...s }; delete n[key]; return n; });
      },
      error: () => this.fixingState.update(s => { const n = { ...s }; delete n[key]; return n; }),
    });
  }

  validate(league: League): void {
    this.validateStates.update(s => ({ ...s, [league.id]: 'loading' }));
    this.cache.ensureSeasons();
    this.api.post<any>('league/validate_ratings', { league_id: league.id }).subscribe({
      next: res => {
        this.validateStates.update(s => ({ ...s, [league.id]: 'done' }));
        this.validateResults.update(s => ({ ...s, [league.id]: res }));
        if (!this.expandedId() || this.expandedId() !== league.id) this.toggleLeague(league);
      },
      error: () => this.validateStates.update(s => ({ ...s, [league.id]: 'error' })),
    });
  }

  createdMatchdays(leagueId: string): { season_id: string; matchday_number: number }[] {
    const details = this.migrateResults()[leagueId]?.matchdays_created ?? [];
    return [...details].sort((a: any, b: any) => {
      const aDate = this.cache.seasons().find((s) => s.id === a.season_id)?.start_date ?? '';
      const bDate = this.cache.seasons().find((s) => s.id === b.season_id)?.start_date ?? '';
      if (bDate !== aDate) return bDate.localeCompare(aDate);
      return Number(a.matchday_number) - Number(b.matchday_number);
    });
  }

  migrate(league: League): void {
    this.migrateStates.update((s) => ({ ...s, [league.id]: 'loading' }));
    this.cache.ensureSeasons();
    this.api.post<any>('league/migrate', { league_id: league.id }).subscribe({
      next: (res) => {
        this.migrateStates.update((s) => ({ ...s, [league.id]: 'success' }));
        this.migrateResults.update((s) => ({ ...s, [league.id]: res }));
        if (res?.matchdays_created?.length) {
          console.info('[migrate] matchdays auto-created:', res.matchdays_created);
        }
      },
      error: () => this.migrateStates.update((s) => ({ ...s, [league.id]: 'error' })),
    });
  }
}
