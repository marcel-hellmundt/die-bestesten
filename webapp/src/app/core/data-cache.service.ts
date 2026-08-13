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
  private leagueState    = signal<{ id: string | null; slug: string | null; name: string | null; divisionId: string | null; loaded: boolean }>({ id: null, slug: null, name: null, divisionId: null, loaded: false });
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
      next: data => this.leagueState.set({ id: data.id ?? null, slug: data.slug ?? null, name: data.name ?? null, divisionId: data.division_id ?? null, loaded: true }),
      error: ()   => this.leagueState.set({ id: null, slug: null, name: null, divisionId: null, loaded: true }),
    });
  }

  invalidateLeague(): void {
    this.leagueState.set({ id: null, slug: null, name: null, divisionId: null, loaded: false });
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
