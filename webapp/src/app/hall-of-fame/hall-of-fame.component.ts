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
  team_id: string;
  team_name: string;
  season_id: string;
  manager_id: string;
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

  championsMap = computed(() => {
    const map = new Map<string, number>();
    for (const award of this.awards()) {
      if (!award.name?.toLowerCase().includes('meisterschaft')) continue;
      for (const row of (award.seasons ?? [])) {
        if (row.winner?.manager_id) {
          map.set(row.winner.manager_id, (map.get(row.winner.manager_id) ?? 0) + 1);
        }
      }
    }
    return map;
  });

  trophies(managerId: string): number[] {
    return Array.from({ length: this.championsMap().get(managerId) ?? 0 });
  }

  avatarFailed  = new Set<string>();
  logoFailed    = new Set<string>();

  onAvatarError(id: string): void { this.avatarFailed.add(id); }
  onLogoError(id: string): void   { this.logoFailed.add(id); }

  awardStat(awardName: string, winner: any): number | null {
    if (!winner) return null;
    const n = awardName.toLowerCase();
    if (n.includes('meisterschaft'))  return winner.total_points        ?? null;
    if (n.includes('bank'))           return winner.total_gap            ?? null;
    if (n.includes('bürste') || n.includes('burste')) return winner.min_matchday_points ?? null;
    return null;
  }

  awardStatLabel(awardName: string): string {
    const n = awardName.toLowerCase();
    if (n.includes('meisterschaft'))  return 'Pkt';
    if (n.includes('bank'))           return 'Pkt Diff';
    if (n.includes('bürste') || n.includes('burste')) return 'Min Pkt';
    return '';
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
