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

  seasons = computed(() =>
    [...this.cache.startedSeasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))
  );

  selectedSeasonId = signal<string | null>(null);

  effectiveSeasonId = computed(() => {
    const sel = this.selectedSeasonId();
    const seasons = this.seasons();
    if (sel && seasons.some(s => s.id === sel)) return sel;
    return seasons[0]?.id ?? null;
  });

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

  navigate(teamId: string): void {
    this.router.navigate(['/team', teamId]);
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
