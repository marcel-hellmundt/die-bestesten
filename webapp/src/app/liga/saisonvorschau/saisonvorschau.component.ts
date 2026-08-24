import { Component, computed, inject } from '@angular/core';
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

  private logoErrors = new Set<string>();
  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  teamLogoUrl(teamId: string): string {
    return `${environment.imageApiUrl}/team/${this.seasonId()}/${teamId}.png`;
  }

  positionCounts(t: SaisonvorschauTeam) {
    return POSITIONS.map(pos => ({
      position: pos,
      label: this.positionLabel(pos),
      count: t.position_counts?.[pos] ?? 0,
      min: SQUAD_MIN[pos],
      color: this.positionColor(pos),
    }));
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
}
