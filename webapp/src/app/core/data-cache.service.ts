import { Injectable, inject, signal, computed } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ApiService } from './api.service';
import { Season } from './models/season.model';
import { Division } from './models/division.model';
import { map } from 'rxjs';

const SQUAD_MIN: Record<string, number> = { GOALKEEPER: 1, DEFENDER: 5, MIDFIELDER: 5, FORWARD: 3 };

// Same 7 formations the lineup editor allows.
const VALID_FORMATIONS = [
  [1,3,4,3],[1,3,5,2],[1,4,3,3],[1,4,4,2],[1,4,5,1],[1,5,3,2],[1,5,4,1],
];
const LINEUP_POS_INDEX: Record<string, number> = {
  GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3,
};

export { Division } from './models/division.model';

@Injectable({ providedIn: 'root' })
export class DataCacheService {
  private api = inject(ApiService);

  private seasonsState   = signal<{ data: Season[];   loaded: boolean }>({ data: [], loaded: false });
  private divisionsState = signal<{ data: Division[]; loaded: boolean }>({ data: [], loaded: false });
  private myTeamState    = signal<{ data: { id: string; team_name: string; season_id: string; color: string | null; color_secondary: string | null } | null; loaded: boolean }>({ data: null, loaded: false });
  private squadState     = signal<{ players: any[]; loaded: boolean }>({ players: [], loaded: false });
  private lineupState    = signal<{ hasMatchday: boolean; nominated: any[]; loaded: boolean }>({ hasMatchday: false, nominated: [], loaded: false });
  private leagueState    = signal<{ id: string | null; slug: string | null; name: string | null; divisionId: string | null; fineRuleset: string | null; powerrankingEnabled: boolean; loaded: boolean }>({ id: null, slug: null, name: null, divisionId: null, fineRuleset: null, powerrankingEnabled: true, loaded: false });
  private h2hStatusState = signal<{ exists: boolean; loaded: boolean }>({ exists: false, loaded: false });

  seasons        = computed(() => this.seasonsState().data);
  startedSeasons = computed(() => {
    const today = new Date().toISOString().substring(0, 10);
    return this.seasonsState().data.filter(s => s.start_date <= today);
  });
  divisions = computed(() => this.divisionsState().data);
  myTeamId     = computed(() => this.myTeamState().data?.id ?? null);
  myTeam       = computed(() => this.myTeamState().data);
  myTeamLoaded = computed(() => this.myTeamState().loaded);

  leagueId         = computed(() => this.leagueState().id);
  leagueName       = computed(() => this.leagueState().name);
  leagueDivisionId = computed(() => this.leagueState().divisionId);
  leagueDivision   = computed(() => {
    const id = this.leagueDivisionId();
    return id ? (this.divisionsState().data.find(d => d.id === id) ?? null) : null;
  });
  // Default true (Strafen an) solange nicht geladen — entspricht dem DB-Default 'classic'.
  finesEnabled = computed(() => this.leagueState().fineRuleset !== 'none');
  // Default true solange nicht geladen — entspricht dem DB-Default für league.powerranking_enabled.
  powerrankingEnabled = computed(() => this.leagueState().powerrankingEnabled);

  // "Hot-Takes & Wetten" ist rein hartkodierter, saisonaler Inhalt für die eigene Liga — andere
  // Ligen, die diese Webapp nutzen, sollen weder den Menüpunkt noch die Seite sehen.
  private static readonly HOT_TAKES_LEAGUE_SLUG = 'die-bestesten';
  isHotTakesLeague = computed(() => this.leagueState().slug === DataCacheService.HOT_TAKES_LEAGUE_SLUG);

  // Fallback wie im Backend (getDivisionConfig()): höchste deutsche Division, falls die Liga keine division_id konfiguriert hat.
  private fallbackDivision = computed(() =>
    this.divisionsState().data.find(d => d.level === 1 && d.country_id.toLowerCase() === 'de') ?? null
  );
  pointsBonus = computed(() =>
    this.leagueDivision()?.points_bonus ?? this.fallbackDivision()?.points_bonus ?? 20_000
  );

  squadCount   = computed(() => this.squadState().players.length);

  squadInvalid = computed(() => {
    if (!this.squadState().loaded) return false;
    const counts: Record<string, number> = { GOALKEEPER: 0, DEFENDER: 0, MIDFIELDER: 0, FORWARD: 0 };
    for (const p of this.squadState().players) if (counts[p.position] !== undefined) counts[p.position]++;
    return Object.entries(SQUAD_MIN).some(([pos, min]) => counts[pos] < min);
  });

  lineupInvalid = computed(() => {
    if (!this.lineupState().loaded) return false;
    if (!this.lineupState().hasMatchday) return true;
    const counts = [0, 0, 0, 0];
    for (const p of this.lineupState().nominated) {
      const i = LINEUP_POS_INDEX[p.position];
      if (i !== undefined) counts[i]++;
    }
    return !VALID_FORMATIONS.some(f => f.every((v, i) => v === counts[i]));
  });

  ensureSeasons(): void {
    if (this.seasonsState().loaded) return;
    this.api.get<any[]>('season').pipe(
      map(data => data.map(Season.from))
    ).subscribe(data => {
      this.seasonsState.set({ data, loaded: true });
    });
  }

  ensureMyTeam(): void {
    if (this.myTeamState().loaded) return;
    this.api.get<any>('team/mine').subscribe({
      next: data => this.myTeamState.set({ data, loaded: true }),
      error: (err: HttpErrorResponse) => {
      if (err.status === 404) this.myTeamState.set({ data: null, loaded: true });
    },
    });
  }

  refreshMyTeam(): void {
    this.myTeamState.set({ data: null, loaded: false });
    this.ensureMyTeam();
  }

  ensureSquad(): void {
    if (this.squadState().loaded) return;
    const teamId = this.myTeamId();
    if (!teamId) return;
    this.api.get<any>(`player_in_team?team_id=${teamId}`).subscribe({
      next: data => this.squadState.set({ players: Array.isArray(data) ? data : (data.current ?? []), loaded: true }),
      error: ()   => this.squadState.set({ players: [], loaded: true }),
    });
  }

  invalidateSquad(): void {
    this.squadState.set({ players: [], loaded: false });
  }

  ensureLineup(): void {
    if (this.lineupState().loaded) return;
    const teamId = this.myTeamId();
    if (!teamId) return;
    this.api.get<any>(`team_lineup?team_id=${teamId}`).subscribe({
      next: data => this.lineupState.set({ hasMatchday: !!data?.matchday, nominated: data?.nominated ?? [], loaded: true }),
      error: ()   => this.lineupState.set({ hasMatchday: false, nominated: [], loaded: true }),
    });
  }

  invalidateLineup(): void {
    this.lineupState.set({ hasMatchday: false, nominated: [], loaded: false });
  }

  ensureDivisions(): void {
    if (this.divisionsState().loaded) return;
    this.api.get<any[]>('division').pipe(
      map(data => data.map(Division.from))
    ).subscribe(data => {
      this.divisionsState.set({ data, loaded: true });
    });
  }

  ensureLeague(): void {
    if (this.leagueState().loaded) return;
    this.api.get<any>('league/mine').subscribe({
      next: data => this.leagueState.set({ id: data.id ?? null, slug: data.slug ?? null, name: data.name ?? null, divisionId: data.division_id ?? null, fineRuleset: data.fine_ruleset ?? null, powerrankingEnabled: data.powerranking_enabled ?? true, loaded: true }),
      error: ()   => this.leagueState.set({ id: null, slug: null, name: null, divisionId: null, fineRuleset: null, powerrankingEnabled: true, loaded: true }),
    });
  }

  invalidateLeague(): void {
    this.leagueState.set({ id: null, slug: null, name: null, divisionId: null, fineRuleset: null, powerrankingEnabled: true, loaded: false });
  }

  h2hTournamentEverExisted = computed(() => this.h2hStatusState().exists);

  ensureH2HStatus(): void {
    if (this.h2hStatusState().loaded) return;
    this.api.get<{ exists: boolean }>('h2h/status').subscribe({
      next: data => this.h2hStatusState.set({ exists: !!data.exists, loaded: true }),
      error: () => this.h2hStatusState.set({ exists: false, loaded: true }),
    });
  }

  seasonName(seasonId: string): string {
    const season = this.seasonsState().data.find(s => s.id === seasonId);
    return season ? season.displayName : seasonId;
  }

  divisionName(divisionId: string): string {
    const division = this.divisionsState().data.find(d => d.id === divisionId);
    return division ? division.name : divisionId;
  }

  // Daily cache-buster so updated profile photos are visible within 24 h
  private static readonly _photoBust = new Date().toISOString().slice(0, 10).replace(/-/g, '');

  managerPhotoUrl(id: string | null | undefined): string | null {
    return id ? `https://img.die-bestesten.de/manager/${id}.jpg?v=${DataCacheService._photoBust}` : null;
  }
}
