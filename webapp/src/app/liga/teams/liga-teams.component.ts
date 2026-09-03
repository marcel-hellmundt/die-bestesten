import { Component, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
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

interface ClubLeadingTeamPlayer {
  name: string;
}

interface ClubLeadingTeam {
  id: string;
  team_name: string;
  color: string | null;
  color_secondary: string | null;
  count: number;
  players: ClubLeadingTeamPlayer[];
}

interface ClubLeadingTeamRow {
  id: string;
  name: string;
  short_name: string | null;
  logo_uploaded: boolean;
  leading_team: ClubLeadingTeam | null;
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
          map(data => ({
            data: [...data].sort((a, b) => b.total_value - a.total_value),
            loading: false,
            error: null as string | null,
          })),
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

  // Vereine der Liga-Division mit dem Team, das die meisten aktuellen Kaderspieler dieses
  // Vereins führt — für die "Vereine"-Card unterhalb der Teams-Tabelle.
  private clubState = toSignal(
    toObservable(this.effectiveSeasonId).pipe(
      switchMap(id => {
        if (!id) return of({ data: [] as ClubLeadingTeamRow[], loading: false, error: null as string | null });
        return this.api.get<ClubLeadingTeamRow[]>(`team/clubs?season_id=${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: [] as ClubLeadingTeamRow[], loading: true, error: null as string | null }),
          catchError(() => of({ data: [] as ClubLeadingTeamRow[], loading: false, error: 'Fehler beim Laden' }))
        );
      })
    ),
    { initialValue: { data: [] as ClubLeadingTeamRow[], loading: true, error: null as string | null } }
  );

  clubs        = computed(() => this.clubState().data);
  clubsLoading = computed(() => this.clubState().loading);
  clubsError   = computed(() => this.clubState().error);

  private logoErrors = new Set<string>();

  logoFailed(id: string): boolean { return this.logoErrors.has(id); }
  onLogoError(id: string): void   { this.logoErrors.add(id); }

  teamLogoUrl(t: LigaTeam): string {
    return `${environment.imageApiUrl}/team/${t.season_id}/${t.id}.png`;
  }

  leadingTeamLogoUrl(teamId: string): string {
    return `${environment.imageApiUrl}/team/${this.effectiveSeasonId()}/${teamId}.png`;
  }

  clubLogoUrl(c: ClubLeadingTeamRow): string {
    return c.logo_uploaded
      ? `${environment.imageApiUrl}/club/${c.id}.png`
      : 'img/placeholders/club.png';
  }

  formatValue(v: number): string {
    // 2 Nachkommastellen zwingend noetig, nicht 1: der Marktwert steigt pro Saisonpunkt um
    // 20.000 EUR (division.points_bonus), also in 0,02-Mio-Schritten - mit nur 1 Nachkommastelle
    // waeren nah beieinanderliegende Kaderwerte sonst nicht unterscheidbar.
    if (v >= 1_000_000) return (v / 1_000_000).toFixed(2).replace('.', ',') + ' Mio. €';
    if (v >= 1_000)     return (v / 1_000).toFixed(0) + ' Tsd. €';
    return v.toLocaleString('de-DE') + ' €';
  }

  // Kompakte Badges je Position direkt in der Tabellenzeile - gedimmt sobald der Kader dort
  // unter dem Minimum liegt.
  positionCounts(t: LigaTeam) {
    return POSITIONS.map(pos => {
      const count = t.position_counts?.[pos] ?? 0;
      const min = CONSTRAINTS[pos].min;
      return {
        position: pos,
        label: this.positionLabel(pos),
        count,
        min,
        color: this.positionColor(pos),
        opacity: count < min ? 0.3 : 1,
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

  // Hover-Tooltip über dem führenden Team einer Vereins-Zeile — listet dessen Kaderspieler
  // dieses Vereins auf. Gleiches Edge-Clamp-Muster wie andernorts im Projekt (fixed position,
  // zweiter Messungs-Pass per requestAnimationFrame, hoverSeq gegen veraltete Callbacks).
  @ViewChild('clubTeamTooltipEl') clubTeamTooltipEl?: ElementRef<HTMLElement>;
  tooltipClub  = signal<ClubLeadingTeamRow | null>(null);
  tooltipPos   = signal<{ top: number; left: number } | null>(null);
  tooltipBelow = signal(false);
  tooltipReady = signal(false);

  private static readonly TOOLTIP_EDGE_MARGIN = 24;
  private hoverSeq = 0;

  onTeamHover(event: MouseEvent, club: ClubLeadingTeamRow): void {
    if (!club.leading_team?.players.length) return;
    const seq = ++this.hoverSeq;
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.tooltipClub.set(club);
    this.tooltipBelow.set(false);
    this.tooltipReady.set(false);
    this.tooltipPos.set({ top: rect.top, left: rect.left + rect.width / 2 });

    requestAnimationFrame(() => {
      const el = this.clubTeamTooltipEl?.nativeElement;
      if (!el || seq !== this.hoverSeq) return;

      const margin = LigaTeamsComponent.TOOLTIP_EDGE_MARGIN;
      let top   = rect.top;
      let left  = rect.left + rect.width / 2;
      let below = false;

      const tipRect = el.getBoundingClientRect();

      if (tipRect.top < margin) {
        below = true;
        top = rect.bottom;
      }

      const halfWidth = tipRect.width / 2;
      const maxLeft   = window.innerWidth - margin - halfWidth;
      const minLeft   = margin + halfWidth;
      if (left > maxLeft) left = maxLeft;
      if (left < minLeft) left = minLeft;

      this.tooltipBelow.set(below);
      this.tooltipPos.set({ top, left });
      this.tooltipReady.set(true);
    });
  }

  onTeamLeave(): void {
    this.hoverSeq++;
    this.tooltipClub.set(null);
    this.tooltipPos.set(null);
    this.tooltipReady.set(false);
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
