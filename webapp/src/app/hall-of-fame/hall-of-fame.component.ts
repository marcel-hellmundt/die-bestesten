import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../core/api.service';
import { DataCacheService } from '../core/data-cache.service';

interface AllTimeStandingsEntry {
  id: string;
  manager_name: string;
  alias: string | null;
  total_points: number;
}

interface TopMatchdayEntry {
  points: number;
  matchday_id: string;
  matchday_number: number | null;
  team_name: string;
  season_id: string;
  manager_name: string;
}

@Component({
  selector: 'app-hall-of-fame',
  standalone: false,
  templateUrl: './hall-of-fame.component.html',
  styleUrl: './hall-of-fame.component.scss'
})
export class HallOfFameComponent {
  private api   = inject(ApiService);
  cache         = inject(DataCacheService);

  private state = toSignal(
    this.api.get<{ standings: AllTimeStandingsEntry[]; top_matchdays: TopMatchdayEntry[] }>('all_time_standings').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: null as any, loading: true, error: null as string | null }),
      catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
    )
  );

  private awardsState = toSignal(
    this.api.get<any[]>('award').pipe(
      map(data => ({ data, loading: false })),
      startWith({ data: [] as any[], loading: true }),
      catchError(() => of({ data: [] as any[], loading: false }))
    )
  );

  items         = computed(() => (this.state()?.data?.standings    ?? []) as AllTimeStandingsEntry[]);
  topMatchdays  = computed(() => (this.state()?.data?.top_matchdays ?? []) as TopMatchdayEntry[]);
  loading       = computed(() => this.state()?.loading ?? true);
  error         = computed(() => this.state()?.error   ?? null);
  awards        = computed(() => this.awardsState()?.data ?? []);
  awardsLoading = computed(() => this.awardsState()?.loading ?? true);

  avatarFailed  = new Set<string>();
  logoFailed    = new Set<string>();

  onAvatarError(id: string): void { this.avatarFailed.add(id); }
  onLogoError(id: string): void   { this.logoFailed.add(id); }

  constructor() {
    this.cache.ensureSeasons();
  }
}
