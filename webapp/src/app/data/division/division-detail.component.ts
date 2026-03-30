import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { Division } from '../../core/models/division.model';
import { Club } from '../../core/models/club.model';

@Component({
  selector: 'app-division-detail',
  standalone: false,
  templateUrl: './division-detail.component.html',
  styleUrl: './division-detail.component.scss'
})
export class DivisionDetailComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);
  cache         = inject(DataCacheService);

  private id$ = this.route.paramMap.pipe(map(p => p.get('id')!));

  private divisionState = toSignal(
    this.id$.pipe(
      switchMap(id => this.api.get<any>(`division/${id}`).pipe(
        map(data => ({ data: Division.from(data), loading: false, error: null as string | null })),
        startWith({ data: null as Division | null, loading: true, error: null as string | null }),
        catchError(() => of({ data: null as Division | null, loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  private allClubsState = toSignal(
    this.api.get<any[]>('club').pipe(
      map(data => data.map(Club.from)),
      catchError(() => of([] as Club[]))
    ),
    { initialValue: [] as Club[] }
  );

  division = computed(() => this.divisionState()?.data ?? null);
  loading  = computed(() => this.divisionState()?.loading ?? true);
  error    = computed(() => this.divisionState()?.error ?? null);

  selectedSeasonId = signal<string | null>(null);

  private effectiveSeasonId = computed(() =>
    this.selectedSeasonId() ?? this.cache.seasons()[0]?.id ?? null
  );

  private clubsInSeasonState = toSignal(
    toObservable(this.effectiveSeasonId).pipe(
      switchMap(seasonId => {
        if (!seasonId) return of({ data: [] as any[], loading: false });
        return this.api.get<any[]>(`club_in_season?season_id=${seasonId}`).pipe(
          map(data => ({ data, loading: false })),
          startWith({ data: [] as any[], loading: true }),
          catchError(() => of({ data: [] as any[], loading: false }))
        );
      })
    )
  );

  clubs = computed(() => {
    const divisionId = this.division()?.id;
    const entries    = this.clubsInSeasonState()?.data ?? [];
    const allClubs   = this.allClubsState();
    if (!divisionId) return [] as { club: Club; position: number | null }[];

    const clubMap = new Map(allClubs.map(c => [c.id, c]));

    return entries
      .filter((e: any) => e.division_id === divisionId)
      .map((e: any) => ({ club: clubMap.get(e.club_id) ?? null, position: e.position as number | null }))
      .filter((e): e is { club: Club; position: number | null } => e.club !== null)
      .sort((a, b) => (a.position ?? 999) - (b.position ?? 999));
  });

  clubsLoading = computed(() => this.clubsInSeasonState()?.loading ?? true);

  constructor() {
    this.cache.ensureSeasons();
  }

  selectSeason(id: string): void {
    this.selectedSeasonId.set(id);
  }
}
