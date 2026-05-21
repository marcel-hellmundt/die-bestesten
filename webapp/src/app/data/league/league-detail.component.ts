import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-league-detail',
  standalone: false,
  templateUrl: './league-detail.component.html',
  styleUrl: './league-detail.component.scss',
})
export class LeagueDetailComponent {
  private api    = inject(ApiService);
  private auth   = inject(AuthService);
  private route  = inject(ActivatedRoute);
  private router = inject(Router);
  cache          = inject(DataCacheService);

  isAdmin = computed(() => this.auth.isAdmin());

  private state = toSignal(
    this.route.paramMap.pipe(
      map(params => params.get('id')!),
      switchMap(id =>
        this.api.get<any>(`league/${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null as any, loading: false, error: 'Fehler beim Laden' })),
        )
      ),
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } },
  );

  league  = computed(() => this.state().data);
  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);

  seasonGroups = computed(() => {
    const teams = this.league()?.teams ?? [];
    const seasons = this.cache.seasons();
    const bySeasonId = new Map<string, any[]>();
    for (const t of teams) {
      if (!bySeasonId.has(t.season_id)) bySeasonId.set(t.season_id, []);
      bySeasonId.get(t.season_id)!.push(t);
    }
    return [...bySeasonId.entries()]
      .sort((a, b) => {
        const aDate = seasons.find(s => s.id === a[0])?.start_date ?? '';
        const bDate = seasons.find(s => s.id === b[0])?.start_date ?? '';
        return bDate.localeCompare(aDate);
      })
      .map(([seasonId, teamList]) => ({ seasonId, teams: teamList }));
  });

  expandedSeasonId = signal<string | null>(null);

  toggleSeason(seasonId: string): void {
    this.expandedSeasonId.set(this.expandedSeasonId() === seasonId ? null : seasonId);
  }

  private get leagueId(): string {
    return this.route.snapshot.params['id'];
  }

  // ── Admin tools ─────────────────────────────────────────────────────────────

  migrateState  = signal<'idle' | 'loading' | 'success' | 'error'>('idle');
  migrateResult = signal<any>(null);

  validateState  = signal<'idle' | 'loading' | 'done' | 'error'>('idle');
  validateResult = signal<any>(null);

  migrate(): void {
    this.migrateState.set('loading');
    this.cache.ensureSeasons();
    this.api.post<any>('league/migrate', { league_id: this.leagueId }).subscribe({
      next: res => { this.migrateState.set('success'); this.migrateResult.set(res); },
      error: () => this.migrateState.set('error'),
    });
  }

  validate(): void {
    this.validateState.set('loading');
    this.cache.ensureSeasons();
    this.api.post<any>('league/validate_ratings', { league_id: this.leagueId }).subscribe({
      next: res => { this.validateState.set('done'); this.validateResult.set(res); },
      error: () => this.validateState.set('error'),
    });
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

  isFixing(mm: any, field: string): boolean {
    return this.fixingState()[`${mm.team_id}:${mm.matchday_id}:${field}`] ?? false;
  }

  fixField(mm: any, field: string, value: number): void {
    const leagueId = this.leagueId;
    const key = `${mm.team_id}:${mm.matchday_id}:${field}`;
    if (this.fixingState()[key]) return;
    this.fixingState.update(s => ({ ...s, [key]: true }));
    this.api.post<any>('league/fix_rating', {
      league_id: leagueId, team_id: mm.team_id, matchday_id: mm.matchday_id, field, value,
    }).subscribe({
      next: () => {
        this.validateResult.update(vr => {
          if (!vr) return vr;
          const newMismatches = vr.mismatches
            .map((m: any) => {
              if (m.team_id !== mm.team_id || m.matchday_id !== mm.matchday_id) return m;
              const newFields = { ...m.fields };
              delete newFields[field];
              return { ...m, fields: newFields };
            })
            .filter((m: any) => Object.keys(m.fields).length > 0);
          return { ...vr, mismatches: newMismatches };
        });
        this.fixingState.update(s => { const n = { ...s }; delete n[key]; return n; });
      },
      error: () => this.fixingState.update(s => { const n = { ...s }; delete n[key]; return n; }),
    });
  }

  createdMatchdays(): { season_id: string; matchday_number: number }[] {
    const details = this.migrateResult()?.matchdays_created ?? [];
    return [...details].sort((a: any, b: any) => {
      const aDate = this.cache.seasons().find(s => s.id === a.season_id)?.start_date ?? '';
      const bDate = this.cache.seasons().find(s => s.id === b.season_id)?.start_date ?? '';
      if (bDate !== aDate) return bDate.localeCompare(aDate);
      return Number(a.matchday_number) - Number(b.matchday_number);
    });
  }

  back(): void {
    this.router.navigate(['..'], { relativeTo: this.route });
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
