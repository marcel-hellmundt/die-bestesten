import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, distinctUntilChanged, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
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
  cache          = inject(DataCacheService);

  constructor() {
    this.cache.ensureLeague();
  }

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
    return `${environment.imageApiUrl}/team/${this.seasonId() ?? ''}/${teamId}.png`;
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

  // Beide Charts zeigen immer die komplette Saison (Spieltag 1–34), auch wenn erst ein Teil gespielt ist.
  readonly matchdayCount = 34;
  private readonly plotW = this.cW - this.padL - this.padR;
  private readonly plotH = this.cH - this.padT - this.padB;
  private readonly slotW = this.plotW / this.matchdayCount;

  /** Mitte des Slots für Spieltag n (1-basiert). */
  private slotCenter(n: number): number {
    return this.padL + (n - 1) * this.slotW + this.slotW / 2;
  }

  /** Ein Slot je Spieltag 1–34: X-Achsen-Beschriftung (nur 1, 5, 10, … 34) + Hover-Fläche. */
  slots = computed(() =>
    Array.from({ length: this.matchdayCount }, (_, i) => {
      const n = i + 1;
      return {
        number: n,
        x:      this.padL + i * this.slotW,
        width:  this.slotW,
        center: this.slotCenter(n),
        showLabel: n === 1 || n % 5 === 0 || n === this.matchdayCount,
      };
    })
  );

  /** Ratings je Spieltagsnummer, angereichert um kumulierte Punkte und Platzveränderung. */
  private byMatchday = computed(() => {
    const map = new Map<number, any>();
    let cum = 0;
    let prevRank: number | null = null;
    for (const r of this.ratings()) {
      if (!r.invalid) cum += Number(r.points);
      const rank = r.running_rank != null ? Number(r.running_rank) : null;
      map.set(Number(r.matchday_number), {
        ...r,
        cumulative_points: cum,
        rank_change: rank != null && prevRank != null ? prevRank - rank : null, // >0 = verbessert
      });
      if (rank != null) prevRank = rank;
    }
    return map;
  });

  // ── Chart 1: points bar chart ─────────────────────────────────────────────
  pointsChart = computed(() => {
    const rs = this.ratings();
    if (rs.length === 0) return null;

    const color  = this.teamColor() ?? '#bf1d00';
    const maxPts = Math.max(...rs.map(r => +r.points), 1);
    const barW   = Math.min(this.slotW * 0.72, 32);

    const bars = rs.map(r => {
      const n    = Number(r.matchday_number);
      const barH = (Math.max(+r.points, 0) / maxPts) * this.plotH;
      return {
        number: n,
        x:      this.slotCenter(n) - barW / 2,
        y:      this.padT + this.plotH - barH,
        width:  barW,
        height: Math.max(barH, 1),
        fill:   r.invalid ? '#d1d5db' : color,
      };
    });

    const yTicks = [
      { y: this.padT,              label: String(maxPts) },
      { y: this.padT + this.plotH, label: '0' },
    ];

    return { bars, yTicks };
  });

  // ── Chart 2: cumulative position line chart ───────────────────────────────
  positionChart = computed(() => {
    const valid = this.ratings().filter(r => r.running_rank != null);
    if (valid.length === 0) return null;

    const color     = this.teamColor() ?? '#bf1d00';
    const teamCount = this.teamCount();
    const posR      = Math.max(teamCount - 1, 1);
    const posY = (pos: number) => this.padT + ((pos - 1) / posR) * this.plotH;

    const dots = valid.map(r => ({
      number: Number(r.matchday_number),
      x:      this.slotCenter(Number(r.matchday_number)),
      y:      posY(+r.running_rank),
    }));

    const line = dots.map((d, i) => `${i === 0 ? 'M' : 'L'}${d.x.toFixed(1)},${d.y.toFixed(1)}`).join(' ');

    const yTicks: { y: number; label: string }[] = [
      { y: posY(1),         label: '1' },
      { y: posY(teamCount), label: String(teamCount) },
    ];

    return { dots, line, color, yTicks };
  });

  // ── Hover-Tooltip (beide Charts) ──────────────────────────────────────────
  tip = signal<{ chart: 'points' | 'position'; number: number; left: number; top: number } | null>(null);

  tipData = computed(() => {
    const t = this.tip();
    return t ? (this.byMatchday().get(t.number) ?? null) : null;
  });

  /** Positioniert den Tooltip über der Mitte des gehoverten Spieltag-Slots, am Card-Rand geklemmt. */
  showTip(event: Event, chart: 'points' | 'position', number: number): void {
    const target = event.target as Element;
    const card   = target.closest('.chart-card') as HTMLElement | null;
    if (!card) return;
    const cardRect = card.getBoundingClientRect();
    const slotRect = target.getBoundingClientRect();
    const margin   = 80; // halbe Tooltip-Breite, damit er nicht aus der Card ragt
    const center   = slotRect.left + slotRect.width / 2 - cardRect.left;
    const left     = Math.min(Math.max(center, margin), cardRect.width - margin);
    this.tip.set({ chart, number, left, top: slotRect.top - cardRect.top });
  }

  hideTip(): void { this.tip.set(null); }

  rankChangeLabel(change: number | null): string {
    if (change == null || change === 0) return '±0';
    return change > 0 ? `▲ ${change}` : `▼ ${-change}`;
  }
}
