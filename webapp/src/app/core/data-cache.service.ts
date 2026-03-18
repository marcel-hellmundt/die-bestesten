import { Injectable, inject, signal, computed } from '@angular/core';
import { ApiService } from './api.service';
import { Season } from './models/season.model';
import { map } from 'rxjs';

interface Division {
  id: string;
  name: string;
  level: number;
  country_id: string;
}

@Injectable({ providedIn: 'root' })
export class DataCacheService {
  private api = inject(ApiService);

  private seasonsState   = signal<{ data: Season[];   loaded: boolean }>({ data: [], loaded: false });
  private divisionsState = signal<{ data: Division[]; loaded: boolean }>({ data: [], loaded: false });

  seasons   = computed(() => this.seasonsState().data);
  divisions = computed(() => this.divisionsState().data);

  ensureSeasons(): void {
    if (this.seasonsState().loaded) return;
    this.api.get<any[]>('season').pipe(
      map(data => data.map(Season.from))
    ).subscribe(data => {
      this.seasonsState.set({ data, loaded: true });
    });
  }

  ensureDivisions(): void {
    if (this.divisionsState().loaded) return;
    this.api.get<Division[]>('division').subscribe(data => {
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
}
