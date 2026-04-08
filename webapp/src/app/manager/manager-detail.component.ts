import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../core/api.service';
import { DataCacheService } from '../core/data-cache.service';
import { Team } from '../core/models/team.model';

@Component({
  selector: 'app-manager-detail',
  standalone: false,
  templateUrl: './manager-detail.component.html',
  styleUrl: './manager-detail.component.scss'
})
export class ManagerDetailComponent {
  private api   = inject(ApiService);
  cache         = inject(DataCacheService);

  private id$ = inject(ActivatedRoute).paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`manager/${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    )
  );

  manager       = computed(() => this.state()?.data ?? null);
  loading       = computed(() => this.state()?.loading ?? true);
  error         = computed(() => this.state()?.error ?? null);
  private seasonStartDate = (seasonId: string): string => {
    return this.cache.seasons().find(s => s.id === seasonId)?.start_date ?? '';
  };

  teams       = computed(() => {
    const raw: Team[] = (this.manager()?.teams ?? []).map(Team.from);
    return raw.sort((a, b) =>
      this.seasonStartDate(b.season_id).localeCompare(this.seasonStartDate(a.season_id))
    );
  });
  totalPoints  = computed(() => this.teams().reduce((s, t) => s + t.total_points, 0));
  highlights       = computed(() => (this.manager()?.highlights       ?? []) as any[]);
  lowlights        = computed(() => (this.manager()?.lowlights        ?? []) as any[]);
  favoritePlayers  = computed(() => (this.manager()?.favorite_players ?? []) as any[]);

  // Bar chart
  readonly chartW = 360;
  readonly chartH = 160;
  readonly padL   = 28;
  readonly padR   = 12;
  readonly padT   = 12;
  readonly padB   = 24;

  chartData = computed(() => {
    const teams = [...this.teams()].reverse(); // oldest → newest (left → right)
    if (teams.length === 0) return null;

    const maxPoints = Math.max(...teams.map(t => t.total_points), 1);
    const plotW = this.chartW - this.padL - this.padR;
    const plotH = this.chartH - this.padT - this.padB;
    const n     = teams.length;
    const barW  = Math.min((plotW / n) * 0.6, 40);

    const toX = (i: number) => this.padL + (i + 0.5) / n * plotW;
    const toY = (pts: number) => this.padT + plotH - (pts / maxPoints) * plotH;

    const bars = teams.map((t, i) => ({
      x:      toX(i) - barW / 2,
      y:      toY(t.total_points),
      w:      barW,
      h:      (t.total_points / maxPoints) * plotH,
      color:  t.color ?? 'var(--color-accent)',
      label:  this.cache.seasonName(t.season_id),
      points: t.total_points,
      cx:     toX(i),
    }));

    const yTicks = [
      { y: toY(maxPoints), label: String(maxPoints) },
      { y: toY(0),         label: '0' },
    ];

    // Placement line: 1 = top, last = bottom, normalized per season
    const linePoints = teams
      .map((t, i) => {
        if (t.season_placement == null || t.season_team_count == null || t.season_team_count <= 1) return null;
        const norm = (t.season_placement - 1) / (t.season_team_count - 1); // 0 = 1st, 1 = last
        const y    = this.padT + norm * plotH;
        return { x: toX(i), y, placement: t.season_placement, teamCount: t.season_team_count };
      })
      .filter((p): p is NonNullable<typeof p> => p !== null);

    const pathD = linePoints.length >= 2
      ? linePoints.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ')
      : null;

    return { bars, yTicks, linePoints, pathD };
  });

  avatarFailed   = false;
  logoErrors     = new Set<string>();
  playerErrors   = new Set<string>();
  onLogoError(teamId: string)     { this.logoErrors.add(teamId); }
  onPlayerError(playerId: string) { this.playerErrors.add(playerId); }

  constructor() {
    this.cache.ensureSeasons();
  }
}
