import { Component, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
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

interface MovementEntry {
  manager_id: string;
  manager_name: string;
  rank: number;
  cumulative_points: number;
}

interface MovementSeasonColumn {
  season_id: string;
  entries: MovementEntry[];
}

interface MovementTooltip {
  entry: MovementEntry;
  top: number;
  left: number;
}

@Component({
  selector: 'app-hall-of-fame',
  standalone: false,
  templateUrl: './hall-of-fame.component.html',
  styleUrl: './hall-of-fame.component.scss'
})
export class HallOfFameComponent {
  private api    = inject(ApiService);
  private router = inject(Router);

  navigate(path: any[]): void { this.router.navigate(path); }
  cache         = inject(DataCacheService);

  private state = toSignal(
    this.api.get<{ standings: AllTimeStandingsEntry[]; top_matchdays: TopMatchdayEntry[] }>('all_time_standings').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: null as any, loading: true, error: null as string | null }),
      catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
    )
  );

  // Bewegung in der ewigen Tabelle: eine Spalte je Saison (chronologisch, siehe API), Köpfe
  // darin bereits absteigend nach Rang sortiert vom Backend geliefert.
  private movementState = toSignal(
    this.api.get<MovementSeasonColumn[]>('all_time_standings/by_season').pipe(
      map(data => ({ data, loading: false })),
      startWith({ data: [] as MovementSeasonColumn[], loading: true }),
      catchError(() => of({ data: [] as MovementSeasonColumn[], loading: false })),
    )
  );

  movementColumns = computed(() => this.movementState()?.data ?? []);
  movementLoading = computed(() => this.movementState()?.loading ?? true);

  movementTooltip     = signal<MovementTooltip | null>(null);
  hoveredManagerId     = signal<string | null>(null);
  connectorPath        = signal<string | null>(null);
  connectorSvgSize     = signal({ w: 0, h: 0 });

  @ViewChild('movementGridEl') private movementGridEl?: ElementRef<HTMLElement>;

  onMovementHeadEnter(event: MouseEvent, entry: MovementEntry): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.movementTooltip.set({ entry, top: rect.top, left: rect.left + rect.width / 2 });
    this.hoveredManagerId.set(entry.manager_id);
    this.updateConnectorLine(entry.manager_id);
  }

  onMovementHeadLeave(): void {
    this.movementTooltip.set(null);
    this.hoveredManagerId.set(null);
    this.connectorPath.set(null);
  }

  // Verbindungslinie durch alle Köpfe desselben Managers über die Saison-Spalten hinweg (gleiches
  // Grundmuster wie h2h.component.ts's updateLines() für die K.o.-Baum-Linien) — Koordinaten
  // relativ zum scrollbaren Grid-Container selbst (inkl. scrollLeft), damit die Linie beim
  // horizontalen Scrollen mit den Köpfen mitwandert statt an der Viewport-Position zu kleben.
  private updateConnectorLine(managerId: string): void {
    const grid = this.movementGridEl?.nativeElement;
    if (!grid) return;

    const gridRect = grid.getBoundingClientRect();
    this.connectorSvgSize.set({ w: grid.scrollWidth, h: grid.clientHeight });

    const heads = Array.from(
      grid.querySelectorAll<HTMLElement>(`[data-manager-id="${managerId}"]`)
    );
    if (heads.length < 2) {
      this.connectorPath.set(null);
      return;
    }

    const points = heads.map(el => {
      const r = el.getBoundingClientRect();
      return {
        x: r.left - gridRect.left + grid.scrollLeft + r.width / 2,
        y: r.top - gridRect.top + r.height / 2,
      };
    });

    this.connectorPath.set(points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' '));
  }

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
