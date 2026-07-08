import { Component, ElementRef, Injector, OnDestroy, ViewChild, afterNextRender, computed, effect, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { catchError, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-h2h',
  standalone: false,
  templateUrl: './h2h.component.html',
  styleUrl: './h2h.component.scss',
})
export class H2HComponent implements OnDestroy {
  @ViewChild('koSection') private koSectionRef?: ElementRef<HTMLElement>;
  private api    = inject(ApiService);
  private router = inject(Router);
  cache          = inject(DataCacheService);

  seasons = computed(() =>
    [...this.cache.startedSeasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))
  );

  selectedIndex      = signal(0);
  selectedSeason     = computed(() => this.seasons()[this.selectedIndex()] ?? null);
  selectedSeasonYear = computed(() => {
    const year = this.selectedSeason()?.start_date?.slice(0, 4);
    if (!year) return '';
    return `${year}/${String(+year + 1).slice(-2)}`;
  });
  canDecrement       = computed(() => this.selectedIndex() < this.seasons().length - 1);
  canIncrement       = computed(() => this.selectedIndex() > 0);
  decrement()        { if (this.canDecrement()) this.selectedIndex.update(i => i + 1); }
  increment()        { if (this.canIncrement()) this.selectedIndex.update(i => i - 1); }

  onSeasonChange(id: string): void {
    const idx = this.seasons().findIndex(s => s.id === id);
    if (idx >= 0) this.selectedIndex.set(idx);
  }

  private state = toSignal(
    toObservable(this.selectedSeason).pipe(
      filter(s => !!s),
      switchMap(season =>
        this.api.get<any>(`h2h?season_id=${season!.id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' })),
        )
      )
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  loading         = computed(() => this.state().loading);
  error           = computed(() => this.state().error);
  groups          = computed(() => (this.state().data?.groups ?? []) as any[]);
  knockoutMatches = computed(() => (this.state().data?.knockout_matches ?? []) as any[]);

  knockoutPhases = computed(() => {
    const matches = this.knockoutMatches();
    const order   = ['final', 'semifinal', 'quarterfinal'];
    const byPhase = new Map<string, any[]>();
    for (const m of matches) {
      if (!byPhase.has(m.phase)) byPhase.set(m.phase, []);
      byPhase.get(m.phase)!.push(m);
    }
    return order
      .filter(p => byPhase.has(p))
      .map(phase => {
        const phaseMatches = byPhase.get(phase)!;
        const matchupMap   = new Map<string, any[]>();
        for (const m of phaseMatches) {
          const key = [m.home_team_id, m.away_team_id].sort().join('_');
          if (!matchupMap.has(key)) matchupMap.set(key, []);
          matchupMap.get(key)!.push(m);
        }
        const matchups = Array.from(matchupMap.values()).map(legs => {
          const sorted = [...legs].sort((a, b) => a.leg - b.leg);
          const teamAId = sorted[0].home_team_id;
          const teamBId = sorted[0].away_team_id;
          let aggA = 0, aggB = 0;
          let hasResults = false;
          for (const leg of sorted) {
            if (leg.home_goals != null) {
              hasResults = true;
              aggA += leg.home_team_id === teamAId ? leg.home_goals : leg.away_goals;
              aggB += leg.home_team_id === teamBId ? leg.home_goals : leg.away_goals;
            }
          }
          return { legs: sorted, teamAId, teamBId, aggA, aggB, hasResults };
        });
        return { phase, label: this.phaseLabel(phase), matchups };
      });
  });

  phaseLabel(phase: string): string {
    return ({ quarterfinal: 'Viertelfinale', semifinal: 'Halbfinale', final: 'Finale' } as any)[phase] ?? phase;
  }

  matchupLabel(phase: string, index: number): string {
    if (phase === 'quarterfinal') return `VF${index + 1}`;
    if (phase === 'semifinal')    return `HF${index + 1}`;
    return 'Finale';
  }

  qfSeedLabels(index: number): [string, string] {
    const labels: [string, string][] = [['A1','B2'], ['B1','A2'], ['C1','D2'], ['D1','C2']];
    return labels[index] ?? ['', ''];
  }

  isLive(m: any): boolean {
    return !!m.kickoff_date && new Date(m.kickoff_date) <= new Date() && m.completed === false;
  }

  navigateToMatch(id: string): void {
    this.router.navigate(['/liga/h2h', id]);
  }

  // ── Team logos ─────────────────────────────────────────────────────────────

  private logoErrors = new Set<string>();

  teamLogoUrl(teamId: string): string {
    const sid = this.selectedSeason()?.id ?? '';
    return `${environment.imageApiUrl}/team/${sid}/${teamId}.png`;
  }

  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  koLines  = signal<string[]>([]);
  svgSize  = signal({ w: 0, h: 0 });

  private resizeObs?: ResizeObserver;

  constructor() {
    this.cache.ensureSeasons();

    const injector = inject(Injector);
    afterNextRender(() => {
      this.updateLines();
      this.resizeObs = new ResizeObserver(() => this.updateLines());
      if (this.koSectionRef?.nativeElement) {
        this.resizeObs.observe(this.koSectionRef.nativeElement);
      }
      effect(() => {
        this.knockoutPhases();
        setTimeout(() => this.updateLines(), 0);
      }, { injector });
    });
  }

  ngOnDestroy(): void {
    this.resizeObs?.disconnect();
  }

  private updateLines(): void {
    const section = this.koSectionRef?.nativeElement;
    if (!section) return;
    const sr = section.getBoundingClientRect();
    this.svgSize.set({ w: sr.width, h: sr.height });

    const getCard = (phase: string, idx: number) =>
      section.querySelector<HTMLElement>(`[data-phase="${phase}"][data-index="${idx}"]`);

    const botMid = (el: HTMLElement) => {
      const r = el.getBoundingClientRect();
      return { x: (r.left + r.right) / 2 - sr.left, y: r.bottom - sr.top };
    };
    const topMid = (el: HTMLElement) => {
      const r = el.getBoundingClientRect();
      return { x: (r.left + r.right) / 2 - sr.left, y: r.top - sr.top };
    };
    const curve = (a: {x:number,y:number}, b: {x:number,y:number}) => {
      const mid = (a.y + b.y) / 2;
      return `M${a.x},${a.y} C${a.x},${mid} ${b.x},${mid} ${b.x},${b.y}`;
    };

    const pairs: [string, number, string, number][] = [
      ['quarterfinal', 0, 'semifinal', 0],
      ['quarterfinal', 2, 'semifinal', 0],
      ['quarterfinal', 1, 'semifinal', 1],
      ['quarterfinal', 3, 'semifinal', 1],
      ['semifinal',    0, 'final',     0],
      ['semifinal',    1, 'final',     0],
    ];

    const lines: string[] = [];
    for (const [fp, fi, tp, ti] of pairs) {
      const from = getCard(fp, fi);
      const to   = getCard(tp, ti);
      if (from && to) lines.push(curve(topMid(from), botMid(to)));
    }
    this.koLines.set(lines);
  }
}
