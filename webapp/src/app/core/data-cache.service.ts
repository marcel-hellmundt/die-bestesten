import { Injectable, inject, signal, computed } from '@angular/core';
import { ApiService } from './api.service';
import { Season } from './models/season.model';
import { Division } from './models/division.model';
import { map } from 'rxjs';

export { Division } from './models/division.model';

@Injectable({ providedIn: 'root' })
export class DataCacheService {
  private api = inject(ApiService);

  private seasonsState   = signal<{ data: Season[];   loaded: boolean }>({ data: [], loaded: false });
  private divisionsState = signal<{ data: Division[]; loaded: boolean }>({ data: [], loaded: false });
  private myTeamState    = signal<{ data: { id: string; team_name: string; season_id: string } | null; loaded: boolean }>({ data: null, loaded: false });

  seasons   = computed(() => this.seasonsState().data);
  divisions = computed(() => this.divisionsState().data);
  myTeamId  = computed(() => this.myTeamState().data?.id ?? null);

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
