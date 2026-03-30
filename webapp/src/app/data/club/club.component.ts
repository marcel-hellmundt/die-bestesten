import { Component, computed, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, forkJoin, map, of, startWith, switchMap } from 'rxjs';
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

  // seasons() is DESC by start_date → [0] = current, [1] = previous
  private currentSeasonId = computed(() => this.cache.seasons()[0]?.id ?? null);
  private prevSeasonId    = computed(() => this.cache.seasons()[1]?.id ?? null);

  private currentSeasonState = toSignal(
    toObservable(this.currentSeasonId).pipe(
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

  private currentSeasonEntries = computed(() => this.currentSeasonState()?.data ?? []);
  private prevSeasonEntries    = computed(() => this.prevSeasonState()?.data    ?? []);

  bundesligaClubs = computed(() => {
    const division = this.cache.divisions().find(d => d.name === '1. Bundesliga');
    if (!division) return [] as { club: Club; position: number | null }[];

    // Who is in Bundesliga *this* season
    const currentIds = new Set(
      this.currentSeasonEntries()
        .filter((e: any) => e.division_id === division.id)
        .map((e: any) => e.club_id as string)
    );

    // Positions from *previous* season for sorting
    const positionMap = new Map(
      this.prevSeasonEntries()
        .filter((e: any) => e.division_id === division.id)
        .map((e: any) => [e.club_id as string, e.position as number | null])
    );

    return this.filteredItems()
      .filter(c => currentIds.has(c.id))
      .map(c => ({ club: c, position: positionMap.get(c.id) ?? null }))
      .sort((a, b) => (a.position ?? 999) - (b.position ?? 999));
  });

  otherClubs = computed(() => {
    const blIds = new Set(this.bundesligaClubs().map(e => e.club.id));
    return this.filteredItems().filter(c => !blIds.has(c.id));
  });

  isAdmin      = computed(() => this.auth.isAdmin());
  migrateState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  sanityState    = signal<'idle' | 'loading' | 'done'>('idle');
  sanityWarnings = signal<string[]>([]);

  constructor() {
    this.cache.ensureSeasons();
    this.cache.ensureDivisions();
  }

  runSanityCheck(): void {
    const seasons   = this.cache.seasons();
    const divisions = this.cache.divisions();
    if (!seasons.length || !divisions.length) return;

    this.sanityState.set('loading');

    const requests = Object.fromEntries(
      seasons.map(s => [s.id, this.api.get<any[]>(`club_in_season?season_id=${s.id}`)])
    );

    forkJoin(requests).subscribe({
      next: (results: Record<string, any[]>) => {
        const warnings: string[] = [];
        const divisionMap = new Map(divisions.map(d => [d.id, d]));
        const seasonMap   = new Map(seasons.map(s => [s.id, s.displayName]));

        for (const [seasonId, entries] of Object.entries(results)) {
          const seasonName = seasonMap.get(seasonId) ?? seasonId;

          // Group by division
          const byDivision = new Map<string, any[]>();
          for (const e of entries) {
            if (!e.division_id) continue;
            if (!byDivision.has(e.division_id)) byDivision.set(e.division_id, []);
            byDivision.get(e.division_id)!.push(e);
          }

          for (const [divisionId, divEntries] of byDivision) {
            const division = divisionMap.get(divisionId);
            const divName  = division?.name ?? divisionId;

            // Check seats
            if (division?.seats != null && divEntries.length > division.seats) {
              warnings.push(`${seasonName} — ${divName}: ${divEntries.length} Clubs, aber nur ${division.seats} Plätze`);
            }

            // Check duplicate positions
            const positions = divEntries.map(e => e.position).filter(p => p != null);
            const seen = new Set<number>();
            for (const pos of positions) {
              if (seen.has(pos)) {
                warnings.push(`${seasonName} — ${divName}: Position ${pos} ist mehrfach vergeben`);
              }
              seen.add(pos);
            }
          }
        }

        this.sanityWarnings.set(warnings);
        this.sanityState.set('done');
      },
      error: () => this.sanityState.set('idle'),
    });
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
