import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, tap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { League } from '../../core/models/league.model';
import { ROLE_LABEL, ROLE_ORDER } from '../../core/constants';

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

  readonly roleOrder = ROLE_ORDER;
  readonly roleLabel = ROLE_LABEL;

  sortedRoles(roles: string[]): string[] {
    const r = roles?.length ? roles : ['manager'];
    return [...r].sort((a, b) => this.roleOrder.indexOf(a) - this.roleOrder.indexOf(b));
  }

  readonly assignableRoles = ['admin', 'maintainer'];
  roleTogglingState = signal<Record<string, boolean>>({});

  isRoleToggling(managerId: string, role: string): boolean {
    return this.roleTogglingState()[`${managerId}:${role}`] ?? false;
  }

  toggleRole(leagueId: string, manager: any, role: string): void {
    const key = `${manager.id}:${role}`;
    if (this.roleTogglingState()[key]) return;

    const hasRole = (manager.roles ?? []).includes(role);
    this.roleTogglingState.update(s => ({ ...s, [key]: true }));

    const req = hasRole
      ? this.api.delete<any>(`manager/${manager.id}/roles/${role}`)
      : this.api.post<any>(`manager/${manager.id}/roles`, { role });

    req.subscribe({
      next: () => {
        const newRoles = hasRole
          ? (manager.roles ?? []).filter((r: string) => r !== role)
          : [...(manager.roles ?? []), role];
        this.managersCache.update(cache => {
          const list = (cache[leagueId] ?? []).map((m: any) =>
            m.id === manager.id ? { ...m, roles: newRoles } : m
          );
          return { ...cache, [leagueId]: list };
        });
        this.roleTogglingState.update(s => { const n = { ...s }; delete n[key]; return n; });
      },
      error: () => {
        this.roleTogglingState.update(s => { const n = { ...s }; delete n[key]; return n; });
      },
    });
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

  groupedMismatches(mismatches: any[]): { seasonId: string; matchdayNumber: number; items: any[] }[] {
    const groups: { seasonId: string; matchdayNumber: number; items: any[] }[] = [];
    const seen = new Map<string, number>();
    for (const mm of mismatches) {
      const key = `${mm.season_id}:${mm.matchday_number}`;
      if (!seen.has(key)) {
        seen.set(key, groups.length);
        groups.push({ seasonId: mm.season_id, matchdayNumber: mm.matchday_number, items: [] });
      }
      groups[seen.get(key)!].items.push(mm);
    }
    return groups;
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
