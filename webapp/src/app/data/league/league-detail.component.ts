import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { environment } from '../../../environments/environment';

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
    const opening = this.expandedSeasonId() !== seasonId;
    this.expandedSeasonId.set(opening ? seasonId : null);
    if (opening) this.loadH2HStatus(seasonId);
  }

  private h2hStatus = signal<Record<string, { hasGroups: boolean; hasQF: boolean; hasSF: boolean; hasFinal: boolean }>>({});

  private loadH2HStatus(seasonId: string): void {
    if (this.h2hStatus()[seasonId] !== undefined) return;
    this.api.get<any>(`h2h?season_id=${seasonId}`).subscribe({
      next: data => {
        const kos = data?.knockout_matches ?? [];
        this.h2hStatus.update(s => ({
          ...s,
          [seasonId]: {
            hasGroups: (data?.groups ?? []).length > 0,
            hasQF:     kos.some((m: any) => m.phase === 'quarterfinal'),
            hasSF:     kos.some((m: any) => m.phase === 'semifinal'),
            hasFinal:  kos.some((m: any) => m.phase === 'final'),
          },
        }));
      },
      error: () => {},
    });
  }

  h2hDone(seasonId: string, action: 'generate' | 'quarterfinals' | 'semifinals' | 'final'): boolean {
    const s = this.h2hStatus()[seasonId];
    if (!s) return false;
    const map = { generate: 'hasGroups', quarterfinals: 'hasQF', semifinals: 'hasSF', final: 'hasFinal' } as const;
    return s[map[action]];
  }

  teamLogoUrl(team: any): string {
    return `${environment.imageApiUrl}/img/team/${team.season_id}/${team.id}.png`;
  }

  logoFailed = new Set<string>();
  onLogoError(teamId: string): void { this.logoFailed.add(teamId); }

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

  // ── H2H tournament actions ──────────────────────────────────────────────────

  readonly h2hActions = [
    { key: 'generate'      as const, label: 'Gruppenphase generieren' },
    { key: 'quarterfinals' as const, label: 'Viertelfinale auslosen' },
    { key: 'semifinals'    as const, label: 'Halbfinale auslosen' },
    { key: 'final'         as const, label: 'Finale auslosen' },
  ];

  h2hResetStates = signal<Record<string, 'idle' | 'loading'>>({});

  h2hResetState(seasonId: string): 'idle' | 'loading' {
    return this.h2hResetStates()[seasonId] ?? 'idle';
  }

  resetH2H(seasonId: string): void {
    const key = seasonId;
    this.h2hResetStates.update(s => ({ ...s, [key]: 'loading' }));
    this.api.post<any>('h2h/reset', { league_id: this.leagueId, season_id: seasonId }).subscribe({
      next: () => {
        this.h2hResetStates.update(s => ({ ...s, [key]: 'idle' }));
        this.h2hStatus.update(s => ({
          ...s,
          [seasonId]: { hasGroups: false, hasQF: false, hasSF: false, hasFinal: false },
        }));
        this.h2hStates.update(s => {
          const n = { ...s };
          for (const a of this.h2hActions) delete n[`${seasonId}:${a.key}`];
          return n;
        });
        this.h2hMessages.update(s => {
          const n = { ...s };
          for (const a of this.h2hActions) delete n[`${seasonId}:${a.key}`];
          return n;
        });
      },
      error: () => this.h2hResetStates.update(s => ({ ...s, [key]: 'idle' })),
    });
  }

  h2hStates   = signal<Record<string, 'idle' | 'loading' | 'success' | 'error'>>({});
  h2hMessages = signal<Record<string, string>>({});

  h2hState(seasonId: string, action: string): 'idle' | 'loading' | 'success' | 'error' {
    return this.h2hStates()[`${seasonId}:${action}`] ?? 'idle';
  }

  h2hMessage(seasonId: string, action: string): string {
    return this.h2hMessages()[`${seasonId}:${action}`] ?? '';
  }

  runH2H(seasonId: string, action: 'generate' | 'quarterfinals' | 'semifinals' | 'final'): void {
    const endpoints: Record<string, string> = {
      generate:      'h2h/generate',
      quarterfinals: 'h2h/draw_quarterfinals',
      semifinals:    'h2h/draw_semifinals',
      final:         'h2h/draw_final',
    };
    const key = `${seasonId}:${action}`;
    this.h2hStates.update(s => ({ ...s, [key]: 'loading' }));
    this.h2hMessages.update(s => ({ ...s, [key]: '' }));
    this.api.post<any>(endpoints[action], { league_id: this.leagueId, season_id: seasonId }).subscribe({
      next: res => {
        const msg = action === 'generate'
          ? `${res.groups} Gruppen, ${res.matches} Matches`
          : `${res.matches} Matches angelegt`;
        this.h2hStates.update(s => ({ ...s, [key]: 'success' }));
        this.h2hMessages.update(s => ({ ...s, [key]: msg }));
        const doneMap = { generate: 'hasGroups', quarterfinals: 'hasQF', semifinals: 'hasSF', final: 'hasFinal' } as const;
        this.h2hStatus.update(s => {
          const cur = s[seasonId] ?? { hasGroups: false, hasQF: false, hasSF: false, hasFinal: false };
          return { ...s, [seasonId]: { ...cur, [doneMap[action]]: true } };
        });
      },
      error: err => {
        this.h2hStates.update(s => ({ ...s, [key]: 'error' }));
        this.h2hMessages.update(s => ({ ...s, [key]: err?.error?.message ?? 'Fehler' }));
      },
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
