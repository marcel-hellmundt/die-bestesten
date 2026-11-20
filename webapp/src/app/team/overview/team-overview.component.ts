import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, distinctUntilChanged, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { environment } from '../../../environments/environment';

/** Spalte der Spieltags-Tabelle, über deren Icons der Spieler-Tooltip aufgeht */
type StatKind = 'sds' | 'goals' | 'assists' | 'goals_assists' | 'clean_sheet' | 'yellow_red' | 'red';

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
    const pts    = rs.map(r => +r.points);
    const maxPts = Math.max(...pts, 1);
    const minPts = Math.min(...pts, 0);   // Minuspunkte: Achse reicht unter 0, Balken hängen nach unten
    const barW   = Math.min(this.slotW * 0.72, 32);

    const valueY = (v: number) => this.padT + ((maxPts - v) / (maxPts - minPts)) * this.plotH;
    const zeroY  = valueY(0);

    const bars = rs.map((r, i) => {
      const n  = Number(r.matchday_number);
      const vY = valueY(pts[i]);
      return {
        number: n,
        x:      this.slotCenter(n) - barW / 2,
        y:      Math.min(vY, zeroY),
        width:  barW,
        height: Math.max(Math.abs(zeroY - vY), 1),
        fill:   r.invalid ? '#d1d5db' : color,
      };
    });

    const yTicks = [
      { y: valueY(maxPts), label: String(maxPts) },
      { y: zeroY,          label: '0' },
    ];
    if (minPts < 0) yTicks.push({ y: valueY(minPts), label: String(minPts) });

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

  // ── Spieler-Tooltip (Tabelle) ─────────────────────────────────────────────
  // Hover/Tippen auf die Icons einer Spalte (SdS, Tore, Vorlagen, Weiße Weste, Karten) zeigt die Spieler dahinter.
  // Geladen erst beim ersten Hover über einen Spieltag (GET /team/:id/highlights) und je Spieltag gemerkt — eine
  // Abfrage liefert die Spieler aller Spalten der Zeile. Fest am Fenster positioniert (position: fixed), da die
  // Tabelle mobil waagerecht scrollt und einen absolut positionierten Tooltip abschneiden würde.
  // Desktop: Hover, mobil: Antippen (Tipp daneben/Scrollen schließt).
  statTip = signal<{ rating: any; kind: StatKind; left: number; top: number; below: boolean } | null>(null);

  /** Spieler je Spieltag (matchday_id): Liste, 'loading' oder 'error' */
  private highlights = signal<Record<string, any[] | 'loading' | 'error'>>({});

  readonly positionLabel: Record<string, string> = { GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU' };

  readonly tipTitle: Record<StatKind, string> = {
    sds: 'Spieler des Spieltags', goals: 'Tore', assists: 'Vorlagen', goals_assists: 'Tore und Vorlagen',
    clean_sheet: 'Weiße Weste', yellow_red: 'Gelb-Rote Karten', red: 'Rote Karten',
  };

  /** Hat die Zeile in dieser Spalte überhaupt etwas (dann lässt sich der Tooltip öffnen)? */
  hasStat(r: any, kind: StatKind): boolean {
    switch (kind) {
      case 'sds':           return +r.sds > 0;
      case 'goals':         return +r.goals > 0;
      case 'assists':       return +r.assists > 0;
      case 'goals_assists': return +r.goals > 0 || +r.assists > 0;
      case 'clean_sheet':   return +r.clean_sheet > 0;
      case 'yellow_red':    return +r.yellow_red_cards > 0;
      case 'red':           return +r.red_cards > 0;
    }
  }

  tipState(r: any): any[] | 'loading' | 'error' { return this.highlights()[r.matchday_id] ?? 'loading'; }

  tipPlayers(r: any, kind: StatKind): any[] {
    const list = this.tipState(r);
    if (!Array.isArray(list)) return [];
    return list.filter(p => {
      switch (kind) {
        case 'sds':           return p.sds;
        case 'goals':         return p.goals > 0;
        case 'assists':       return p.assists > 0;
        case 'goals_assists': return p.goals > 0 || p.assists > 0;
        case 'clean_sheet':   return p.clean_sheet;
        case 'yellow_red':    return p.yellow_red_card;
        case 'red':           return p.red_card;
      }
    });
  }

  private loadHighlights(r: any): void {
    const id = r.matchday_id, teamId = this.teamId();
    const cached = this.highlights()[id];
    if (!teamId || (cached && cached !== 'error')) return;
    this.highlights.update(h => ({ ...h, [id]: 'loading' }));
    this.api.get<any[]>(`team/${teamId}/highlights?matchday_id=${id}`).subscribe({
      next: list => this.highlights.update(h => ({ ...h, [id]: list ?? [] })),
      error: () => this.highlights.update(h => ({ ...h, [id]: 'error' })),
    });
  }

  private showStatTip(event: Event, rating: any, kind: StatKind): void {
    if (!this.hasStat(rating, kind)) return;
    this.loadHighlights(rating);
    const rect  = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const half  = 130; // halbe Tooltip-Breite — bleibt seitlich im Fenster
    const left  = Math.min(Math.max(rect.left + rect.width / 2, half + 8), window.innerWidth - half - 8);
    const below = rect.top < 220; // oben zu wenig Platz → unter der Zelle (Höhe steht vor dem Laden noch nicht fest)
    this.statTip.set({ rating, kind, left, top: below ? rect.bottom : rect.top, below });
  }

  hideStatTip(): void { this.statTip.set(null); }

  /** Nur echte Maus: ein Antippen löst sonst erst ein simuliertes Betreten und gleich danach den Klick aus,
   *  der Tooltip ginge sofort wieder zu. Touch läuft über toggleTip(). */
  hoverTip(event: PointerEvent, rating: any | null, kind: StatKind): void {
    if (event.pointerType !== 'mouse') return;
    if (rating) this.showStatTip(event, rating, kind);
    else this.hideStatTip();
  }

  toggleTip(event: Event, rating: any, kind: StatKind): void {
    event.stopPropagation(); // sonst schließt der Dokument-Klick ihn sofort wieder
    const t = this.statTip();
    if (t && t.rating === rating && t.kind === kind) this.hideStatTip();
    else this.showStatTip(event, rating, kind);
  }

  @HostListener('document:click')
  @HostListener('window:scroll')
  onOutsideOrScroll(): void { if (this.statTip()) this.hideStatTip(); }

  playerPhotoUrl(p: any): string | null {
    return p.photo_season_id ? `${environment.imageApiUrl}/player/${p.photo_season_id}/${p.player_id}.png` : null;
  }

  rankChangeLabel(change: number | null): string {
    if (change == null || change === 0) return '±0';
    return change > 0 ? `▲ ${change}` : `▼ ${-change}`;
  }
}
