import { Component, computed, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { environment } from '../../../environments/environment';

interface LigaTeam {
  id: string;
  team_name: string;
  color: string | null;
  color_secondary: string | null;
  season_id: string;
  manager_id: string;
  manager_name: string;
  alias: string | null;
  squad_valid: boolean;
  total_value: number;
  position_counts: Record<string, number>;
}

// Mirrors squad.component.ts's CONSTRAINTS — same min/max squad requirements per position.
const CONSTRAINTS: Record<string, { min: number; max: number }> = {
  GOALKEEPER: { min: 1, max: 2 },
  DEFENDER:   { min: 5, max: 6 },
  MIDFIELDER: { min: 5, max: 6 },
  FORWARD:    { min: 3, max: 4 },
};

const POSITIONS = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];

@Component({
  selector: 'app-liga-teams',
  standalone: false,
  templateUrl: './liga-teams.component.html',
  styleUrl: './liga-teams.component.scss',
})
export class LigaTeamsComponent {
  private api    = inject(ApiService);
  private cache  = inject(DataCacheService);
  private router = inject(Router);

  seasons = computed(() =>
    [...this.cache.startedSeasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))
  );

  selectedIndex = signal(0);

  selectedSeason = computed(() => this.seasons()[this.selectedIndex()] ?? null);

  effectiveSeasonId = computed(() => this.selectedSeason()?.id ?? null);

  canDecrement = computed(() => this.selectedIndex() < this.seasons().length - 1);
  canIncrement = computed(() => this.selectedIndex() > 0);

  decrement() { if (this.canDecrement()) this.selectedIndex.update(i => i + 1); }
  increment() { if (this.canIncrement()) this.selectedIndex.update(i => i - 1); }

  onSeasonChange(id: string): void {
    const idx = this.seasons().findIndex(s => s.id === id);
    if (idx >= 0) this.selectedIndex.set(idx);
  }

  private state = toSignal(
    toObservable(this.effectiveSeasonId).pipe(
      switchMap(id => {
        if (!id) return of({ data: [] as LigaTeam[], loading: false, error: null as string | null });
        return this.api.get<LigaTeam[]>(`team?season_id=${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: [] as LigaTeam[], loading: true, error: null as string | null }),
          catchError(() => of({ data: [] as LigaTeam[], loading: false, error: 'Fehler beim Laden' }))
        );
      })
    ),
    { initialValue: { data: [] as LigaTeam[], loading: true, error: null as string | null } }
  );

  teams   = computed(() => this.state().data);
  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);

  private logoErrors    = new Set<string>();
  private managerErrors = new Set<string>();

  logoFailed(teamId: string): boolean    { return this.logoErrors.has(teamId); }
  managerFailed(mId: string): boolean    { return this.managerErrors.has(mId); }
  onLogoError(teamId: string): void      { this.logoErrors.add(teamId); }
  onManagerError(mId: string): void      { this.managerErrors.add(mId); }

  teamLogoUrl(t: LigaTeam): string {
    return `${environment.imageApiUrl}/team/${t.season_id}/${t.id}.png`;
  }

  managerPhotoUrl(t: LigaTeam): string {
    return `${environment.imageApiUrl}/manager/${t.manager_id}.jpg`;
  }

  formatValue(v: number): string {
    if (v >= 1_000_000) return (v / 1_000_000).toFixed(1).replace('.', ',') + ' Mio. €';
    if (v >= 1_000)     return (v / 1_000).toFixed(0) + ' Tsd. €';
    return v.toLocaleString('de-DE') + ' €';
  }

  // Squad-validity tooltip (desktop hover only, see .squad-validity-tooltip media query) —
  // mirrors squad.component.ts's positionStats(), just without the pending-offer bubble state
  // since that's specific to viewing your own team's open bids.
  tooltipTeam = signal<LigaTeam | null>(null);
  tooltipPos  = signal<{ top: number; left: number } | null>(null);

  onValidityEnter(event: MouseEvent, t: LigaTeam): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.tooltipTeam.set(t);
    this.tooltipPos.set({ top: rect.top, left: rect.left + rect.width / 2 });
  }

  onValidityLeave(): void {
    this.tooltipTeam.set(null);
    this.tooltipPos.set(null);
  }

  positionStats(t: LigaTeam) {
    return POSITIONS.map(pos => {
      const { min, max } = CONSTRAINTS[pos];
      const count = t.position_counts?.[pos] ?? 0;
      return {
        position: pos,
        bubbles: Array.from({ length: max }, (_, i) => ({
          filled: i < count,
          isMin:  i < min,
        })),
      };
    });
  }

  positionLabel(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'TOR',
      DEFENDER:   'ABW',
      MIDFIELDER: 'MIT',
      FORWARD:    'STU',
    };
    return map[pos] ?? pos;
  }

  positionColor(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'var(--position-goalkeeper)',
      DEFENDER:   'var(--position-defender)',
      MIDFIELDER: 'var(--position-midfielder)',
      FORWARD:    'var(--position-forward)',
    };
    return map[pos] ?? 'transparent';
  }

  navigate(teamId: string): void {
    this.router.navigate(['/team', teamId]);
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
