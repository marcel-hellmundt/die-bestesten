import { Component, computed, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { Club } from '../../core/models/club.model';

@Component({
  selector: 'app-data-club',
  standalone: false,
  templateUrl: './club.component.html',
  styleUrl: './club.component.scss'
})
export class ClubDataComponent {
  private api   = inject(ApiService);
  private auth  = inject(AuthService);
  cache         = inject(DataCacheService);

  private reload$ = new BehaviorSubject<void>(undefined);

  private state = toSignal(
    this.reload$.pipe(
      switchMap(() => this.api.get<any[]>('club').pipe(
        map(data => ({ data: data.map(Club.from), loading: false, error: null as string | null })),
        startWith({ data: [] as Club[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Club[], loading: false, error: 'Fehler beim Laden' }))
      ))
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
      (i.short_name ?? '').toLowerCase().includes(q)
    );
  });

  // Previous season = second entry (seasons are DESC by start_date)
  private prevSeasonId = computed(() => this.cache.seasons()[1]?.id ?? null);

  private prevSeasonState = toSignal(
    toObservable(this.prevSeasonId).pipe(
      switchMap(id => {
        if (!id) return of({ data: [] as any[], loading: false });
        return this.api.get<any[]>(`club_in_season?season_id=${id}`).pipe(
          map(data => ({ data, loading: false })),
          startWith({ data: [] as any[], loading: true }),
          catchError(() => of({ data: [] as any[], loading: false }))
        );
      })
    )
  );

  private prevSeasonEntries = computed(() => this.prevSeasonState()?.data ?? []);

  bundesligaClubs = computed(() => {
    const division = this.cache.divisions().find(d => d.name === '1. Bundesliga');
    if (!division) return [] as Club[];

    const entries = this.prevSeasonEntries().filter(e => e.division_id === division.id);
    const ids = new Set(entries.map((e: any) => e.club_id));
    const positionMap = new Map(entries.map((e: any) => [e.club_id, e.position as number | null]));

    return this.filteredItems()
      .filter(c => ids.has(c.id))
      .sort((a, b) => (positionMap.get(a.id) ?? 999) - (positionMap.get(b.id) ?? 999));
  });

  otherClubs = computed(() => {
    const blIds = new Set(this.bundesligaClubs().map(c => c.id));
    return this.filteredItems().filter(c => !blIds.has(c.id));
  });

  isAdmin      = computed(() => this.auth.isAdmin());
  migrateState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  constructor() {
    this.cache.ensureSeasons();
    this.cache.ensureDivisions();
  }

  migrate(): void {
    this.migrateState.set('loading');
    this.api.post<{ status: boolean; migrated: number }>('club/migrate').subscribe({
      next: () => {
        this.migrateState.set('success');
        this.reload$.next();
      },
      error: () => this.migrateState.set('error'),
    });
  }
}
