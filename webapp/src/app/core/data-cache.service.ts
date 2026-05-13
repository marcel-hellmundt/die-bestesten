import { Injectable, inject, signal, computed } from '@angular/core';
import { ApiService } from './api.service';
import { Season } from './models/season.model';
import { Division } from './models/division.model';
import { map } from 'rxjs';

const SQUAD_MIN: Record<string, number> = { GOALKEEPER: 1, DEFENDER: 5, MIDFIELDER: 5, FORWARD: 3 };

export { Division } from './models/division.model';

@Injectable({ providedIn: 'root' })
export class DataCacheService {
  private api = inject(ApiService);

  private seasonsState   = signal<{ data: Season[];   loaded: boolean }>({ data: [], loaded: false });
  private divisionsState = signal<{ data: Division[]; loaded: boolean }>({ data: [], loaded: false });
  private myTeamState    = signal<{ data: { id: string; team_name: string; season_id: string; color: string | null } | null; loaded: boolean }>({ data: null, loaded: false });
  private squadState     = signal<{ players: any[]; loaded: boolean }>({ players: [], loaded: false });

  seasons   = computed(() => this.seasonsState().data);
  divisions = computed(() => this.divisionsState().data);
  myTeamId     = computed(() => this.myTeamState().data?.id ?? null);
  myTeam       = computed(() => this.myTeamState().data);
  myTeamLoaded = computed(() => this.myTeamState().loaded);

  squadInvalid = computed(() => {
    if (!this.squadState().loaded) return false;
    const counts: Record<string, number> = { GOALKEEPER: 0, DEFENDER: 0, MIDFIELDER: 0, FORWARD: 0 };
    for (const p of this.squadState().players) if (counts[p.position] !== undefined) counts[p.position]++;
    return Object.entries(SQUAD_MIN).some(([pos, min]) => counts[pos] < min);
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
      error: ()   => this.myTeamState.set({ data: null, loaded: true }),
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

  ensureDivisions(): void {
    if (this.divisionsState().loaded) return;
    this.api.get<any[]>('division').pipe(
      map(data => data.map(Division.from))
    ).subscribe(data => {
      this.divisionsState.set({ data, loaded: true });
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
    return id ? `https://img.die-bestesten.de/img/manager/${id}.jpg?v=${DataCacheService._photoBust}` : null;
  }
}
