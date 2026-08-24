import { Component, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { environment } from '../../../environments/environment';

interface SaisonvorschauTeam {
  id: string;
  team_name: string;
  color: string | null;
  color_secondary: string | null;
  manager_id: string;
  manager_name: string;
  alias: string | null;
  squad_valid: boolean;
  position_counts: Record<string, number>;
  previous_season_points: number;
  newcomer_count: number;
  newcomer_players: string[];
}

interface ClubRef {
  id: string;
  name: string;
  short_name?: string | null;
  logo_uploaded: boolean;
}

interface ClubTeamCount {
  team_id: string;
  team_name: string;
  color: string | null;
  color_secondary: string | null;
  count: number;
  players: string[];
}

interface SaisonvorschauResponse {
  season_id: string | null;
  previous_season_id: string | null;
  teams: SaisonvorschauTeam[];
  promoted_clubs: ClubRef[];
  promoted_club_teams: ClubTeamCount[];
  special_clubs: ClubRef[];
  special_club_teams: ClubTeamCount[];
}

type SortField = 'previous_season_points' | 'newcomer_count';

const POSITIONS = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];
const SQUAD_MIN: Record<string, number> = { GOALKEEPER: 1, DEFENDER: 5, MIDFIELDER: 5, FORWARD: 3 };

@Component({
  selector: 'app-saisonvorschau',
  standalone: false,
  templateUrl: './saisonvorschau.component.html',
  styleUrl: './saisonvorschau.component.scss',
})
export class SaisonvorschauComponent {
  private api    = inject(ApiService);
  private router = inject(Router);

  private state = toSignal(
    this.api.get<SaisonvorschauResponse>('saisonvorschau').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: null as SaisonvorschauResponse | null, loading: true, error: null as string | null }),
      catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
    ),
    { initialValue: { data: null as SaisonvorschauResponse | null, loading: true, error: null as string | null } }
  );

  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);
  seasonId = computed(() => this.state().data?.season_id ?? null);
  teams    = computed(() => this.state().data?.teams ?? []);

  promotedClubs      = computed(() => this.state().data?.promoted_clubs ?? []);
  promotedClubTeams  = computed(() => this.state().data?.promoted_club_teams ?? []);
  specialClubs       = computed(() => this.state().data?.special_clubs ?? []);
  specialClubTeams   = computed(() => this.state().data?.special_club_teams ?? []);

  promotedClubsLabel = computed(() =>
    this.promotedClubs().map(c => c.name).join(', ') || 'Keine Aufsteiger in dieser Saison'
  );
  specialClubsLabel = computed(() =>
    this.specialClubs().map(c => c.name).join(', ')
  );

  sortField = signal<SortField>('previous_season_points');
  sortDir   = signal<'asc' | 'desc'>('desc');

  sortedTeams = computed(() => {
    const field = this.sortField();
    const dir   = this.sortDir() === 'asc' ? 1 : -1;
    return [...this.teams()].sort((a, b) => (a[field] - b[field]) * dir);
  });

  toggleSort(field: SortField): void {
    if (this.sortField() === field) {
      this.sortDir.update(d => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      this.sortField.set(field);
      this.sortDir.set('desc');
    }
  }

  private logoErrors = new Set<string>();
  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  teamLogoUrl(teamId: string): string {
    return `${environment.imageApiUrl}/team/${this.seasonId()}/${teamId}.png`;
  }

  positionCounts(t: SaisonvorschauTeam) {
    return POSITIONS.map(pos => {
      const count = t.position_counts?.[pos] ?? 0;
      const min   = SQUAD_MIN[pos];
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
    const map: Record<string, string> = { GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU' };
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

  // Spielerlisten-Tooltip — gleiches Positionier-/Edge-Clamp-Muster wie die Kader-Gültigkeit-
  // Tooltip auf /liga/teams (liga-teams.component.ts), nur mit einer kompakten Namensliste statt
  // Bubbles. Ein einziger Tooltip wird von allen drei Hover-Zielen geteilt (Neuzugänge-Spalte in
  // der Tabelle, sowie die Anzahl in beiden Vereins-Karten) — jeweils mit eigenem Titel/Liste.
  @ViewChild('countTooltipEl') countTooltipEl?: ElementRef<HTMLElement>;
  tooltipData  = signal<{ title: string; players: string[] } | null>(null);
  tooltipPos   = signal<{ top: number; left: number } | null>(null);
  tooltipBelow = signal(false);
  tooltipReady = signal(false);

  private static readonly TOOLTIP_EDGE_MARGIN = 24;
  private hoverSeq = 0;

  onCountHover(event: MouseEvent, title: string, players: string[]): void {
    if (!players.length) return;
    const seq = ++this.hoverSeq;
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.tooltipData.set({ title, players });
    this.tooltipBelow.set(false);
    this.tooltipReady.set(false);
    this.tooltipPos.set({ top: rect.top, left: rect.left + rect.width / 2 });

    requestAnimationFrame(() => {
      const el = this.countTooltipEl?.nativeElement;
      if (!el || seq !== this.hoverSeq) return;

      const margin = SaisonvorschauComponent.TOOLTIP_EDGE_MARGIN;
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

  onCountLeave(): void {
    this.hoverSeq++;
    this.tooltipData.set(null);
    this.tooltipPos.set(null);
    this.tooltipReady.set(false);
  }
}
