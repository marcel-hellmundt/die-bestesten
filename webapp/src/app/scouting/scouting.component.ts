import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, combineLatest, of, switchMap } from 'rxjs';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { ApiService } from '../core/api.service';
import { DataCacheService } from '../core/data-cache.service';

interface WatchlistEntry {
  id: string;
  player_id: string;
  displayname: string;
  photo_uploaded: boolean;
  position: 'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD' | null;
  price: number | null;
  season_id: string | null;
  club_id: string | null;
  club_name: string | null;
  club_short_name: string | null;
  club_logo_uploaded: boolean;
  current_team: {
    team_id: string;
    team_name: string;
    color: string | null;
    team_season_id: string;
    manager_name: string;
    alias: string | null;
  } | null;
  created_at: string;
}

@Component({
  selector: 'app-scouting',
  standalone: false,
  templateUrl: './scouting.component.html',
  styleUrl: './scouting.component.scss',
})
export class ScoutingComponent {
  private api    = inject(ApiService);
  private cache  = inject(DataCacheService);
  private router = inject(Router);

  private myTeam = this.cache.myTeam;

  private refresh = signal(0);

  entries = toSignal(
    combineLatest([toObservable(this.myTeam), toObservable(this.refresh)]).pipe(
      switchMap(([team]) => {
        if (!team) return of([] as WatchlistEntry[]);
        return this.api.get<WatchlistEntry[]>(`watchlist?team_id=${team.id}`).pipe(
          catchError(() => of([] as WatchlistEntry[]))
        );
      })
    ),
    { initialValue: [] as WatchlistEntry[] }
  );

  hasTeam   = computed(() => this.myTeam() !== null);
  removing  = signal<string | null>(null);

  readonly positionLabels: Record<string, string> = {
    FORWARD: 'STU', MIDFIELDER: 'MIT', DEFENDER: 'ABW', GOALKEEPER: 'TOR',
  };

  readonly positionColors: Record<string, string> = {
    FORWARD: 'var(--position-forward)', MIDFIELDER: 'var(--position-midfielder)',
    DEFENDER: 'var(--position-defender)', GOALKEEPER: 'var(--position-goalkeeper)',
  };

  playerPhotoUrl(e: WatchlistEntry): string | null {
    return e.photo_uploaded && e.season_id ? `https://img.die-bestesten.de/player/${e.season_id}/${e.player_id}.png` : null;
  }

  clubLogoUrl(e: WatchlistEntry): string {
    return `https://img.die-bestesten.de/club/${e.club_id}.png`;
  }

  teamLogoUrl(teamId: string, seasonId: string): string {
    return `https://img.die-bestesten.de/team/${seasonId}/${teamId}.png`;
  }

  navigateToPlayer(playerId: string): void {
    this.router.navigate(['/daten/player', playerId]);
  }

  remove(entry: WatchlistEntry): void {
    const team = this.myTeam();
    if (!team || this.removing() === entry.id) return;
    this.removing.set(entry.id);
    this.api.delete<null>(`watchlist/${entry.id}`, { team_id: team.id }).subscribe({
      next: () => { this.removing.set(null); this.refresh.update(v => v + 1); },
      error: () => this.removing.set(null),
    });
  }

  constructor() {
    this.cache.ensureMyTeam();
  }
}
