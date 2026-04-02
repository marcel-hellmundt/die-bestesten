import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, tap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { League } from '../../core/models/league.model';

@Component({
  selector: 'app-data-league',
  standalone: false,
  templateUrl: './league.component.html',
  styleUrl: './league.component.scss'
})
export class LeagueDataComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);

  isAdmin = computed(() => this.auth.isAdmin());

  private state = toSignal(
    this.api.get<any[]>('league').pipe(
      map(data => ({ data: data.map(League.from), loading: false, error: null as string | null })),
      startWith({ data: [] as League[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as League[], loading: false, error: 'Fehler beim Laden' }))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);

  searchQuery   = signal('');
  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(i =>
      i.name.toLowerCase().includes(q) ||
      i.slug.toLowerCase().includes(q)
    );
  });

  expandedId      = signal<string | null>(null);
  managersCache   = signal<Record<string, any[]>>({});
  managersLoading = signal<Record<string, boolean>>({});

  toggleLeague(league: League): void {
    if (this.expandedId() === league.id) {
      this.expandedId.set(null);
      return;
    }
    this.expandedId.set(league.id);
    if (this.managersCache()[league.id]) return;

    this.managersLoading.update(s => ({ ...s, [league.id]: true }));
    this.api.get<any>(`league/${league.id}`).pipe(
      tap(data => {
        this.managersCache.update(s => ({ ...s, [league.id]: data.managers ?? [] }));
        this.managersLoading.update(s => ({ ...s, [league.id]: false }));
      }),
      catchError(() => {
        this.managersLoading.update(s => ({ ...s, [league.id]: false }));
        return of(null);
      }),
    ).subscribe();
  }

  managers(leagueId: string): any[] {
    return this.managersCache()[leagueId] ?? [];
  }

  migrateStates = signal<Record<string, 'idle' | 'loading' | 'success' | 'error'>>({});

  migrateState(leagueId: string): 'idle' | 'loading' | 'success' | 'error' {
    return this.migrateStates()[leagueId] ?? 'idle';
  }

  migrate(league: League): void {
    this.migrateStates.update(s => ({ ...s, [league.id]: 'loading' }));
    this.api.post<any>('league/migrate', { league_id: league.id }).subscribe({
      next: (res) => {
        this.migrateStates.update(s => ({ ...s, [league.id]: 'success' }));
        console.log(`[migrate] ${league.name}`, res);
        if (res?.team_ratings?.skipped_details?.length) {
          console.warn(`[migrate] ${league.name} — ${res.team_ratings.skipped} übersprungene team_ratings (season_id + matchday_number nicht in globaler DB):`, res.team_ratings.skipped_details);
        }
      },
      error: () => this.migrateStates.update(s => ({ ...s, [league.id]: 'error' })),
    });
  }
}
