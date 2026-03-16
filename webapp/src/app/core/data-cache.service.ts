import { Injectable, inject, signal, computed } from '@angular/core';
import { ApiService } from './api.service';
import { Season } from './models/season.model';
import { map } from 'rxjs';

@Injectable({ providedIn: 'root' })
export class DataCacheService {
  private api = inject(ApiService);

  private seasonsState = signal<{ data: Season[]; loaded: boolean }>({ data: [], loaded: false });

  seasons         = computed(() => this.seasonsState().data);
  seasonsLoaded   = computed(() => this.seasonsState().loaded);

  ensureSeasons(): void {
    if (this.seasonsState().loaded) return;
    this.api.get<any[]>('season').pipe(
      map(data => data.map(Season.from))
    ).subscribe(data => {
      this.seasonsState.set({ data, loaded: true });
    });
  }

  seasonName(seasonId: string): string {
    const season = this.seasonsState().data.find(s => s.id === seasonId);
    return season ? season.displayName : seasonId;
  }
}
