import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { environment } from '../../../environments/environment';

interface DraftPlayer {
  id: string;
  kicker_id: number | null;
  displayname: string;
  position: string;
  price: number;
  photo_uploaded: boolean;
  club_id: string;
  club_name: string;
  club_short_name: string | null;
  club_logo_uploaded: boolean;
}

interface DraftImportReport {
  parseError: boolean;
  matchedTeams: number;
  unmatchedTeamNames: string[];
  matchedPlayers: number;
  unmatchedPlayers: { teamName: string; kickerId: string; reason: string }[];
}

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

  // Overridden after a successful draft-assignment, so squad_count/squad_value update
  // immediately without re-triggering the full loading/error pipeline (which would reset
  // the expanded season/team UI state).
  private teamsOverride = signal<any[] | null>(null);

  private refreshTeams(): void {
    this.api.get<any>(`league/${this.leagueId}`).subscribe({
      next: data => this.teamsOverride.set(data.teams ?? []),
      error: () => {},
    });
  }

  seasonGroups = computed(() => {
    const teams = this.teamsOverride() ?? this.league()?.teams ?? [];
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

  // Implemented tournament formats — anything else can't be generated.
  private readonly H2H_SUPPORTED_TEAM_COUNTS = [9, 12];

  h2hTeamCount(seasonId: string): number {
    return this.seasonGroups().find(sg => sg.seasonId === seasonId)?.teams.length ?? 0;
  }

  h2hGenerateDisabled(seasonId: string): boolean {
    return !this.H2H_SUPPORTED_TEAM_COUNTS.includes(this.h2hTeamCount(seasonId));
  }

  // 9-team tournaments skip the quarterfinal stage (3 groups → semifinal directly).
  h2hActionsFor(seasonId: string): typeof this.h2hActions {
    if (this.h2hTeamCount(seasonId) === 9) {
      return this.h2hActions.filter(a => a.key !== 'quarterfinals');
    }
    return this.h2hActions;
  }

  teamLogoUrl(team: any): string {
    return `${environment.imageApiUrl}/team/${team.season_id}/${team.id}.png`;
  }

  logoFailed = new Set<string>();
  onLogoError(teamId: string): void { this.logoFailed.add(teamId); }

  private get leagueId(): string {
    return this.route.snapshot.params['id'];
  }

  // ── Admin tools ─────────────────────────────────────────────────────────────

  validateState  = signal<'idle' | 'loading' | 'done' | 'error'>('idle');
  validateResult = signal<any>(null);

  validate(): void {
    this.validateState.set('loading');
    this.cache.ensureSeasons();
    this.api.post<any>('league/validate_ratings', { league_id: this.leagueId }).subscribe({
      next: res => { this.validateState.set('done'); this.validateResult.set(res); },
      error: () => this.validateState.set('error'),
    });
  }

  // Overridden nach erfolgreichem PATCH, damit die Auswahl sofort umschaltet ohne die ganze
  // Detail-Pipeline (und damit den aufgeklappten Saison/Team-UI-State) neu zu laden.
  private fineRulesetOverride = signal<'classic' | 'none' | null>(null);
  fineRulesetSaving = signal(false);

  fineRuleset = computed<'classic' | 'none'>(() =>
    this.fineRulesetOverride() ?? (this.league()?.fine_ruleset === 'none' ? 'none' : 'classic')
  );

  setFineRuleset(value: 'classic' | 'none'): void {
    if (this.fineRuleset() === value || this.fineRulesetSaving()) return;
    this.fineRulesetSaving.set(true);
    this.api.patch<any>(`league/${this.leagueId}`, { fine_ruleset: value }).subscribe({
      next: () => { this.fineRulesetOverride.set(value); this.fineRulesetSaving.set(false); },
      error: () => this.fineRulesetSaving.set(false),
    });
  }

  private powerrankingOverride = signal<boolean | null>(null);
  powerrankingSaving = signal(false);

  powerrankingEnabled = computed<boolean>(() =>
    this.powerrankingOverride() ?? (this.league()?.powerranking_enabled ?? true)
  );

  setPowerrankingEnabled(value: boolean): void {
    if (this.powerrankingEnabled() === value || this.powerrankingSaving()) return;
    this.powerrankingSaving.set(true);
    this.api.patch<any>(`league/${this.leagueId}`, { powerranking_enabled: value }).subscribe({
      next: () => { this.powerrankingOverride.set(value); this.powerrankingSaving.set(false); },
      error: () => this.powerrankingSaving.set(false),
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

  // ── Season awards ───────────────────────────────────────────────────────────

  concludeStates   = signal<Record<string, 'idle' | 'loading' | 'success' | 'skipped' | 'error'>>({});
  concludeMessages = signal<Record<string, string>>({});

  concludeState(seasonId: string): 'idle' | 'loading' | 'success' | 'skipped' | 'error' {
    return this.concludeStates()[seasonId] ?? 'idle';
  }

  concludeMessage(seasonId: string): string {
    return this.concludeMessages()[seasonId] ?? '';
  }

  concludeSeason(seasonId: string): void {
    if (this.concludeStates()[seasonId] === 'loading') return;
    this.concludeStates.update(s => ({ ...s, [seasonId]: 'loading' }));
    this.api.post<any>('league/conclude_season', { league_id: this.leagueId, season_id: seasonId }).subscribe({
      next: res => {
        const state = res.skipped ? 'skipped' : 'success';
        const msg = res.skipped
          ? 'Bereits vergeben'
          : (res.granted as any[] ?? []).map((g: any) => `${g.award}: ${g.team}`).join(' · ');
        this.concludeStates.update(s => ({ ...s, [seasonId]: state }));
        this.concludeMessages.update(s => ({ ...s, [seasonId]: msg }));
      },
      error: err => {
        this.concludeStates.update(s => ({ ...s, [seasonId]: 'error' }));
        this.concludeMessages.update(s => ({ ...s, [seasonId]: err?.error?.message ?? 'Fehler' }));
      },
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

  // ── Draft-Zuweisung ──────────────────────────────────────────────────────────

  readonly POS_LABEL: Record<string, string> = {
    GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU',
  };

  readonly DRAFT_SKIP_REASON_LABEL: Record<string, string> = {
    team_not_found:        'Team nicht gefunden',
    duplicate_in_request:  'Doppelt ausgewählt',
    already_in_team:       'Bereits in einem Team',
    no_price_or_position:  'Kein Marktwert/Position hinterlegt',
    position_limit:        'Positionslimit erreicht',
  };

  private draftPools        = signal<Record<string, DraftPlayer[]>>({});
  private draftPoolLoading  = signal<Record<string, boolean>>({});
  draftSelections           = signal<Record<string, Record<string, DraftPlayer[]>>>({});
  expandedDraftTeamId       = signal<string | null>(null);
  expandedDraftSeasonId     = signal<string | null>(null);
  draftSearchQuery          = signal<string>('');
  draftAssignStates         = signal<Record<string, 'idle' | 'loading' | 'done' | 'error'>>({});
  draftAssignResults        = signal<Record<string, any>>({});

  private ensureDraftPool(seasonId: string): void {
    if (this.draftPools()[seasonId] || this.draftPoolLoading()[seasonId]) return;
    this.draftPoolLoading.update(s => ({ ...s, [seasonId]: true }));
    this.api.get<{ players: DraftPlayer[] }>(`league/${this.leagueId}/draft_pool?season_id=${seasonId}`).subscribe({
      next: res => {
        this.draftPools.update(s => ({ ...s, [seasonId]: res.players }));
        this.draftPoolLoading.update(s => { const n = { ...s }; delete n[seasonId]; return n; });
      },
      error: () => this.draftPoolLoading.update(s => { const n = { ...s }; delete n[seasonId]; return n; }),
    });
  }

  draftPoolIsLoading(seasonId: string): boolean {
    return this.draftPoolLoading()[seasonId] ?? false;
  }

  toggleDraftTeam(seasonId: string, teamId: string): void {
    const opening = this.expandedDraftTeamId() !== teamId;
    this.expandedDraftTeamId.set(opening ? teamId : null);
    this.expandedDraftSeasonId.set(opening ? seasonId : null);
    this.draftSearchQuery.set('');
    if (opening) this.ensureDraftPool(seasonId);
  }

  // Ids selected for ANY team across all seasons — used to hide a player from the search
  // results as soon as they're picked for one team, since a player can only join one team.
  draftSelectedIds = computed(() => {
    const ids = new Set<string>();
    for (const bySeason of Object.values(this.draftSelections())) {
      for (const players of Object.values(bySeason)) {
        for (const p of players) ids.add(p.id);
      }
    }
    return ids;
  });

  filteredDraftPlayers = computed(() => {
    const seasonId = this.expandedDraftSeasonId();
    if (!seasonId) return [];
    const q             = this.draftSearchQuery().trim().toLowerCase();
    const selectedIds   = this.draftSelectedIds();
    const pool          = (this.draftPools()[seasonId] ?? []).filter(p => !selectedIds.has(p.id));
    const matched       = q ? pool.filter(p => p.displayname.toLowerCase().includes(q)) : pool;
    return matched.slice(0, 50);
  });

  draftTeamSelection(seasonId: string, teamId: string): DraftPlayer[] {
    return this.draftSelections()[seasonId]?.[teamId] ?? [];
  }

  draftTeamSum(seasonId: string, teamId: string): number {
    return this.draftTeamSelection(seasonId, teamId).reduce((sum, p) => sum + p.price, 0);
  }

  addDraftPlayer(seasonId: string, teamId: string, player: DraftPlayer): void {
    this.draftSelections.update(s => {
      const bySeason = { ...(s[seasonId] ?? {}) };
      const current  = bySeason[teamId] ?? [];
      if (current.some(p => p.id === player.id)) return s;
      bySeason[teamId] = [...current, player];
      return { ...s, [seasonId]: bySeason };
    });
  }

  removeDraftPlayer(seasonId: string, teamId: string, playerId: string): void {
    this.draftSelections.update(s => {
      const bySeason = { ...(s[seasonId] ?? {}) };
      bySeason[teamId] = (bySeason[teamId] ?? []).filter(p => p.id !== playerId);
      return { ...s, [seasonId]: bySeason };
    });
  }

  draftBatchSummary(seasonId: string): { teamCount: number; playerCount: number; totalPrice: number } {
    const bySeason = this.draftSelections()[seasonId] ?? {};
    let teamCount = 0, playerCount = 0, totalPrice = 0;
    for (const players of Object.values(bySeason)) {
      if (players.length === 0) continue;
      teamCount++;
      playerCount += players.length;
      totalPrice  += players.reduce((sum, p) => sum + p.price, 0);
    }
    return { teamCount, playerCount, totalPrice };
  }

  draftAssignState(seasonId: string): 'idle' | 'loading' | 'done' | 'error' {
    return this.draftAssignStates()[seasonId] ?? 'idle';
  }

  draftAssignResult(seasonId: string): any {
    return this.draftAssignResults()[seasonId] ?? null;
  }

  generateDraftAssignments(seasonId: string): void {
    const bySeason = this.draftSelections()[seasonId] ?? {};
    const assignments = Object.entries(bySeason)
      .filter(([, players]) => players.length > 0)
      .map(([teamId, players]) => ({ team_id: teamId, player_ids: players.map(p => p.id) }));
    if (assignments.length === 0) return;

    this.draftAssignStates.update(s => ({ ...s, [seasonId]: 'loading' }));
    this.api.post<any>(`league/${this.leagueId}/draft_assign`, { season_id: seasonId, assignments }).subscribe({
      next: res => {
        this.draftAssignStates.update(s => ({ ...s, [seasonId]: 'done' }));
        this.draftAssignResults.update(s => ({ ...s, [seasonId]: res }));
        this.draftSelections.update(s => ({ ...s, [seasonId]: {} }));
        this.expandedDraftTeamId.set(null);
        this.expandedDraftSeasonId.set(null);
        this.draftPools.update(s => { const n = { ...s }; delete n[seasonId]; return n; });
        this.refreshTeams();
      },
      error: err => {
        this.draftAssignStates.update(s => ({ ...s, [seasonId]: 'error' }));
        this.draftAssignResults.update(s => ({ ...s, [seasonId]: { message: err?.error?.message ?? 'Fehler' } }));
      },
    });
  }

  // ── Draft-JSON-Import ────────────────────────────────────────────────────────

  readonly DRAFT_IMPORT_SKIP_LABEL: Record<string, string> = {
    invalid_id:        'Ungültige Spieler-ID',
    not_in_pool:        'Nicht im Spielerpool (bereits vergeben/unbekannt)',
    already_selected:  'Bereits in dieser Zuweisung ausgewählt',
  };

  draftImportOpenSeasonId = signal<string | null>(null);
  draftImportText         = signal<string>('');
  draftImportReport       = signal<Record<string, DraftImportReport>>({});

  toggleDraftImport(seasonId: string): void {
    const opening = this.draftImportOpenSeasonId() !== seasonId;
    this.draftImportOpenSeasonId.set(opening ? seasonId : null);
    this.draftImportText.set('');
    if (opening) this.ensureDraftPool(seasonId);
  }

  onDraftImportFileSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => this.draftImportText.set((e.target?.result as string) ?? '');
    reader.readAsText(file);
    (event.target as HTMLInputElement).value = '';
  }

  private parseDraftImportJson(text: string): { name: string; playerList: { id: string }[] }[] {
    const trimmed = text.trim();
    if (!trimmed) return [];
    try {
      const parsed = JSON.parse(trimmed);
      return Array.isArray(parsed) ? parsed : [parsed];
    } catch {
      try {
        const wrapped = '[' + trimmed.replace(/,\s*$/, '').replace(/}\s*\n\s*{/g, '},{') + ']';
        const parsed = JSON.parse(wrapped);
        return Array.isArray(parsed) ? parsed : [parsed];
      } catch {
        return [];
      }
    }
  }

  private parseKickerId(rawId: string): number | null {
    if (!rawId?.startsWith('pl-k')) return null;
    const n = parseInt(rawId.slice(4), 10);
    return Number.isFinite(n) ? n : null;
  }

  runDraftImport(seasonId: string): void {
    const entries = this.parseDraftImportJson(this.draftImportText());
    if (entries.length === 0) {
      this.draftImportReport.update(s => ({
        ...s,
        [seasonId]: { parseError: true, matchedTeams: 0, unmatchedTeamNames: [], matchedPlayers: 0, unmatchedPlayers: [] },
      }));
      return;
    }

    const teams = this.seasonGroups().find(sg => sg.seasonId === seasonId)?.teams ?? [];
    const pool  = this.draftPools()[seasonId] ?? [];
    const report: DraftImportReport = { parseError: false, matchedTeams: 0, unmatchedTeamNames: [], matchedPlayers: 0, unmatchedPlayers: [] };

    for (const entry of entries) {
      const name = entry?.name ?? '';
      const team = teams.find((t: any) => t.manager_name === name)
        ?? teams.find((t: any) => t.manager_name?.trim().toLowerCase() === name?.trim?.().toLowerCase());
      if (!team) {
        report.unmatchedTeamNames.push(name || '(unbenannt)');
        continue;
      }
      report.matchedTeams++;

      for (const ref of entry?.playerList ?? []) {
        const kickerId = this.parseKickerId(ref?.id ?? '');
        if (kickerId === null) {
          report.unmatchedPlayers.push({ teamName: name, kickerId: ref?.id ?? '?', reason: 'invalid_id' });
          continue;
        }
        const player = pool.find(p => p.kicker_id === kickerId);
        if (!player) {
          report.unmatchedPlayers.push({ teamName: name, kickerId: ref.id, reason: 'not_in_pool' });
          continue;
        }
        if (this.draftSelectedIds().has(player.id)) {
          report.unmatchedPlayers.push({ teamName: name, kickerId: ref.id, reason: 'already_selected' });
          continue;
        }
        this.addDraftPlayer(seasonId, team.id, player);
        report.matchedPlayers++;
      }
    }

    this.draftImportReport.update(s => ({ ...s, [seasonId]: report }));
  }

  formatPrice(v: number): string {
    return v.toLocaleString('de-DE') + ' €';
  }

  back(): void {
    this.router.navigate(['..'], { relativeTo: this.route });
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
