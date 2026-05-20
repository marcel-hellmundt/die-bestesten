import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, distinctUntilChanged, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-team-overview',
  standalone: false,
  templateUrl: './team-overview.component.html',
  styleUrl: './team-overview.component.scss'
})
export class TeamOverviewComponent {
  private api    = inject(ApiService);
  private route  = inject(ActivatedRoute);
  private router = inject(Router);

  private id$ = this.route.parent!.paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`team/${id}?include_ratings=1`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  ratings    = computed(() => (this.state().data?.ratings ?? []) as any[]);
  teamColor  = computed(() => (this.state().data?.color as string | null) ?? null);
  teamCount  = computed(() => Number(this.state().data?.team_count ?? 12));
  teamId     = computed(() => (this.state().data?.id as string | null) ?? null);
  seasonId   = computed(() => (this.state().data?.season_id as string | null) ?? null);
  loading    = computed(() => this.state().loading);
  error      = computed(() => this.state().error);

  private h2hRaw = toSignal(
    toObservable(this.state).pipe(
      map(s => s.data?.season_id as string | null),
      filter((sid): sid is string => !!sid),
      distinctUntilChanged(),
      switchMap(sid =>
        this.api.get<any>(`h2h?season_id=${sid}`).pipe(
          catchError(() => of(null))
        )
      )
    ),
    { initialValue: null as any }
  );

  private h2hMatches = computed(() => {
    const data = this.h2hRaw();
    const tid  = this.teamId();
    if (!data || !tid) return [];
    const all: any[] = [];
    for (const g of data.groups ?? []) {
      for (const m of g.matches ?? []) {
        if (m.home_team_id === tid || m.away_team_id === tid) {
          all.push({ ...m, phase: 'group' });
        }
      }
    }
    for (const m of data.knockout_matches ?? []) {
      if (m.home_team_id === tid || m.away_team_id === tid) {
        all.push(m);
      }
    }
    return all.sort((a, b) => (a.matchday_number ?? 999) - (b.matchday_number ?? 999));
  });

  h2hByPhase = computed(() => {
    const order = ['group', 'quarterfinal', 'semifinal', 'final'];
    const byPhase = new Map<string, any[]>();
    for (const m of this.h2hMatches()) {
      const p = m.phase ?? 'group';
      if (!byPhase.has(p)) byPhase.set(p, []);
      byPhase.get(p)!.push(m);
    }
    return order
      .filter(p => byPhase.has(p))
      .map(phase => ({ phase, label: this.phaseLabel(phase), matches: byPhase.get(phase)! }));
  });

  totalPoints    = computed(() => this.ratings().filter((r: any) => !r.invalid).reduce((s: number, r: any) => s + Number(r.points), 0));
  totalFine      = computed(() => this.ratings().reduce((s: number, r: any) => s + Number(r.fine ?? 0), 0));
  totalSds       = computed(() => this.ratings().reduce((s: number, r: any) => s + Number(r.sds ?? 0), 0));
  totalGoals     = computed(() => this.ratings().reduce((s: number, r: any) => s + Number(r.goals ?? 0), 0));
  totalAssists   = computed(() => this.ratings().reduce((s: number, r: any) => s + Number(r.assists ?? 0), 0));
  totalCleanSheet     = computed(() => this.ratings().reduce((s: number, r: any) => s + Number(r.clean_sheet ?? 0), 0));
  totalRedCards       = computed(() => this.ratings().reduce((s: number, r: any) => s + Number(r.red_cards ?? 0), 0));
  totalYellowRedCards = computed(() => this.ratings().reduce((s: number, r: any) => s + Number(r.yellow_red_cards ?? 0), 0));

  range(n: number): number[] { return Array.from({ length: n }, (_, i) => i); }

  phaseLabel(phase: string): string {
    const labels: Record<string, string> = {
      group: 'Gruppenphase',
      quarterfinal: 'Viertelfinale',
      semifinal: 'Halbfinale',
      final: 'Finale',
    };
    return labels[phase] ?? phase;
  }

  navigateToMatch(id: string): void {
    this.router.navigate(['/liga/h2h', id]);
  }

  teamLogoUrl(teamId: string): string {
    return `${environment.imageApiUrl}/img/team/${this.seasonId() ?? ''}/${teamId}.png`;
  }

  private logoErrors = new Set<string>();
  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  // ── Chart layout constants ────────────────────────────────────────────────
  readonly cW   = 700;
  readonly cH   = 130;
  readonly padL = 28;
  readonly padR = 8;
  readonly padT = 8;
  readonly padB = 18;

  // ── Chart 1: points bar chart ─────────────────────────────────────────────
  pointsChart = computed(() => {
    const rs = this.ratings();
    if (rs.length === 0) return null;

    const color  = this.teamColor() ?? '#bf1d00';
    const allPts = rs.map(r => +r.points);
    const maxPts = Math.max(...allPts, 1);
    const plotW  = this.cW - this.padL - this.padR;
    const plotH  = this.cH - this.padT - this.padB;
    const slotW  = plotW / rs.length;
    const barW   = Math.min(slotW * 0.72, 32);

    const bars = rs.map((r, i) => {
      const pts  = +r.points;
      const barH = (pts / maxPts) * plotH;
      return {
        x:      this.padL + i * slotW + (slotW - barW) / 2,
        y:      this.padT + plotH - barH,
        width:  barW,
        height: Math.max(barH, 1),
        fill:   r.invalid ? '#d1d5db' : color,
        labelX: this.padL + i * slotW + slotW / 2,
        label:  r.matchday_number,
      };
    });

    const yTicks = [
      { y: this.padT,          label: String(maxPts) },
      { y: this.padT + plotH,  label: '0' },
    ];

    return { bars, yTicks };
  });

  // ── Chart 2: cumulative position line chart ───────────────────────────────
  positionChart = computed(() => {
    const rs    = this.ratings();
    const valid = rs.filter(r => r.running_rank != null);
    if (valid.length < 2) return null;

    const color    = this.teamColor() ?? '#bf1d00';
    const teamCount = this.teamCount();
    const posR     = Math.max(teamCount - 1, 1);
    const plotW    = this.cW - this.padL - this.padR;
    const plotH    = this.cH - this.padT - this.padB;
    const slotW    = plotW / rs.length;

    const posY = (pos: number) =>
      this.padT + ((pos - 1) / posR) * plotH;

    const dots = valid.map(r => {
      const i = rs.indexOf(r);
      return {
        x:   this.padL + i * slotW + slotW / 2,
        y:   posY(+r.running_rank),
        pos: +r.running_rank,
      };
    });

    const line = dots.map((d, i) => `${i === 0 ? 'M' : 'L'}${d.x.toFixed(1)},${d.y.toFixed(1)}`).join(' ');

    const xLabels = rs.map((r, i) => ({
      x:     this.padL + i * slotW + slotW / 2,
      label: r.matchday_number,
    }));

    const yTicks: { y: number; label: string }[] = [
      { y: posY(1),         label: '1' },
      { y: posY(teamCount), label: String(teamCount) },
    ];

    return { dots, line, color, xLabels, yTicks };
  });
}
