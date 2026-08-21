import { Component, computed, effect, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { AuthService } from '../../auth/auth.service';
import { Division } from '../../core/models/division.model';
import { Club } from '../../core/models/club.model';

@Component({
  selector: 'app-division-detail',
  standalone: false,
  templateUrl: './division-detail.component.html',
  styleUrl: './division-detail.component.scss',
})
export class DivisionDetailComponent {
  private api    = inject(ApiService);
  private route  = inject(ActivatedRoute);
  private auth   = inject(AuthService);

  cache = inject(DataCacheService);

  isAdmin = computed(() => this.auth.isAdmin());

  private id$ = this.route.paramMap.pipe(map((p) => p.get('id')!));

  private divisionState = toSignal(
    this.id$.pipe(
      switchMap((id) =>
        this.api.get<any>(`division/${id}`).pipe(
          map((data) => ({
            data: Division.from(data),
            loading: false,
            error: null as string | null,
          })),
          startWith({ data: null as Division | null, loading: true, error: null as string | null }),
          catchError(() =>
            of({ data: null as Division | null, loading: false, error: 'Fehler beim Laden' }),
          ),
        ),
      ),
    ),
  );

  private allClubsState = toSignal(
    this.api.get<any[]>('club').pipe(
      map((data) => data.map(Club.from)),
      catchError(() => of([] as Club[])),
    ),
    { initialValue: [] as Club[] },
  );

  division = computed(() => this.divisionState()?.data ?? null);
  loading = computed(() => this.divisionState()?.loading ?? true);
  error = computed(() => this.divisionState()?.error ?? null);

  // Seasons sorted newest first for dropdown
  seasons = computed(() =>
    [...this.cache.seasons()].sort((a, b) => b.start_date.localeCompare(a.start_date)),
  );

  selectedIndex = signal(0);

  selectedSeason = computed(() => this.seasons()[this.selectedIndex()] ?? null);

  effectiveSeasonId = computed(() => this.selectedSeason()?.id ?? null);

  canDecrement = computed(() => this.selectedIndex() < this.seasons().length - 1);
  canIncrement = computed(() => this.selectedIndex() > 0);

  decrement() { if (this.canDecrement()) this.selectedIndex.update(i => i + 1); }
  increment() { if (this.canIncrement()) this.selectedIndex.update(i => i - 1); }

  onSeasonChange(id: string): void {
    const idx = this.seasons().findIndex(s => s.id === id);
    if (idx >= 0) this.selectedIndex.set(idx);
  }

  private clubsInSeasonState = toSignal(
    toObservable(this.effectiveSeasonId).pipe(
      switchMap((seasonId) => {
        if (!seasonId) return of({ data: [] as any[], loading: false });
        return this.api.get<any[]>(`club_in_season?season_id=${seasonId}`).pipe(
          map((data) => ({ data, loading: false })),
          startWith({ data: [] as any[], loading: true }),
          catchError(() => of({ data: [] as any[], loading: false })),
        );
      }),
    ),
  );

  clubs = computed(() => {
    const divisionId = this.division()?.id;
    const entries = this.clubsInSeasonState()?.data ?? [];
    const allClubs = this.allClubsState();
    if (!divisionId) return [] as { club: Club; position: number | null }[];

    const clubMap = new Map(allClubs.map((c) => [c.id, c]));

    return entries
      .filter((e: any) => e.division_id === divisionId)
      .map((e: any) => ({
        club: clubMap.get(e.club_id) ?? null,
        position: e.position as number | null,
      }))
      .filter((e): e is { club: Club; position: number | null } => e.club !== null)
      .sort((a, b) => (a.position ?? 999) - (b.position ?? 999));
  });

  clubsLoading = computed(() => this.clubsInSeasonState()?.loading ?? true);

  // Overridden nach erfolgreichem PATCH, damit die Anzeige sofort umschaltet ohne die Division neu zu laden.
  private configOverride = signal<{ starting_budget: number; points_bonus: number } | null>(null);
  configSaving = signal(false);

  startingBudgetDraft = signal<number | null>(null);
  pointsBonusDraft = signal<number | null>(null);
  private configInitialized = false;

  private effectiveConfig = computed(() => {
    const override = this.configOverride();
    if (override) return override;
    const d = this.division();
    return d ? { starting_budget: d.starting_budget, points_bonus: d.points_bonus } : null;
  });

  configDirty = computed(() => {
    const base = this.effectiveConfig();
    if (!base) return false;
    return this.startingBudgetDraft() !== base.starting_budget || this.pointsBonusDraft() !== base.points_bonus;
  });

  configValid = computed(() => (this.startingBudgetDraft() ?? 0) > 0 && (this.pointsBonusDraft() ?? 0) > 0);

  saveConfig(): void {
    const d = this.division();
    if (!d || !this.configValid() || !this.configDirty() || this.configSaving()) return;
    const startingBudget = this.startingBudgetDraft()!;
    const pointsBonus = this.pointsBonusDraft()!;
    this.configSaving.set(true);
    this.api.patch<any>(`division/${d.id}`, { starting_budget: startingBudget, points_bonus: pointsBonus }).subscribe({
      next: () => {
        this.configOverride.set({ starting_budget: startingBudget, points_bonus: pointsBonus });
        this.configSaving.set(false);
      },
      error: () => this.configSaving.set(false),
    });
  }

  constructor() {
    this.cache.ensureSeasons();

    effect(() => {
      const config = this.effectiveConfig();
      if (config && !this.configInitialized) {
        this.configInitialized = true;
        this.startingBudgetDraft.set(config.starting_budget);
        this.pointsBonusDraft.set(config.points_bonus);
      }
    });
  }
}
