import { Component, computed, inject } from '@angular/core';
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
}

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

  private activeSeason = computed(() => {
    const seasons = this.cache.seasons();
    return seasons.length > 0
      ? [...seasons].sort((a, b) => b.start_date.localeCompare(a.start_date))[0]
      : null;
  });

  private state = toSignal(
    toObservable(this.activeSeason).pipe(
      switchMap(season => {
        if (!season) return of({ data: [] as LigaTeam[], loading: false, error: null as string | null });
        return this.api.get<LigaTeam[]>(`team?season_id=${season.id}`).pipe(
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
    return `${environment.imageApiUrl}/img/team/${t.season_id}/${t.id}.png`;
  }

  managerPhotoUrl(t: LigaTeam): string {
    return `${environment.imageApiUrl}/img/manager/${t.manager_id}.jpg`;
  }

  navigate(teamId: string): void {
    this.router.navigate(['/team', teamId]);
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
