import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

@Component({
  selector: 'app-team-overview',
  standalone: false,
  templateUrl: './team-overview.component.html',
  styleUrl: './team-overview.component.scss'
})
export class TeamOverviewComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);

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
  loading   = computed(() => this.state().loading);
  error     = computed(() => this.state().error);

  totalPoints = computed(() => this.ratings().filter((r: any) => !r.invalid).reduce((s: number, r: any) => s + Number(r.points), 0));
  totalFine   = computed(() => this.ratings().reduce((s: number, r: any) => s + Number(r.fine ?? 0), 0));

  range(n: number): number[] { return Array.from({ length: n }, (_, i) => i); }

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
