import { Component, computed, inject, signal } from '@angular/core';
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
export class H2HComponent {
  private api    = inject(ApiService);
  private router = inject(Router);
  cache          = inject(DataCacheService);

  private seasons = computed(() =>
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
    const order   = ['quarterfinal', 'semifinal', 'final'];
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

  navigateToMatch(id: string): void {
    this.router.navigate(['/liga/h2h', id]);
  }

  // ── Team logos ─────────────────────────────────────────────────────────────

  private logoErrors = new Set<string>();

  teamLogoUrl(teamId: string): string {
    const sid = this.selectedSeason()?.id ?? '';
    return `${environment.imageApiUrl}/img/team/${sid}/${teamId}.png`;
  }

  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  constructor() {
    this.cache.ensureSeasons();
  }
}
