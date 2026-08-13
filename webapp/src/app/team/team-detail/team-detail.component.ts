import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { AuthService } from '../../auth/auth.service';

@Component({
  selector: 'app-team-detail',
  standalone: false,
  templateUrl: './team-detail.component.html',
  styleUrl: './team-detail.component.scss'
})
export class TeamDetailComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);
  cache        = inject(DataCacheService);

  private id$ = inject(ActivatedRoute).paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`team/${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    )
  );

  team      = computed(() => this.state()?.data ?? null);
  loading   = computed(() => this.state()?.loading ?? true);
  error     = computed(() => this.state()?.error ?? null);
  isOwnTeam = computed(() => this.team()?.manager_id === this.auth.getManagerId());

  private readonly SQUAD_MIN: Record<string, number> = {
    GOALKEEPER: 1, DEFENDER: 5, MIDFIELDER: 5, FORWARD: 3,
  };

  private squad = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any[]>(`player_in_team?team_id=${id}`).pipe(
          catchError(() => of([] as any[]))
        )
      )
    ),
    { initialValue: [] as any[] }
  );

  squadInvalid = computed(() => {
    const counts: Record<string, number> = {};
    for (const p of this.squad()) {
      if (p.position) counts[p.position] = (counts[p.position] ?? 0) + 1;
    }
    return Object.entries(this.SQUAD_MIN).some(([pos, min]) => (counts[pos] ?? 0) < min);
  });

  // Same 7 formations the lineup editor allows — kept in sync with lineup.component.ts.
  private readonly VALID_FORMATIONS = [
    [1,3,4,3],[1,3,5,2],[1,4,3,3],[1,4,4,2],[1,4,5,1],[1,5,3,2],[1,5,4,1],
  ];
  private readonly POS_INDEX: Record<string, number> = {
    GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3,
  };

  private lineup = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`team_lineup?team_id=${id}`).pipe(
          catchError(() => of(null as any))
        )
      )
    ),
    { initialValue: null as any }
  );

  lineupInvalid = computed(() => {
    const data = this.lineup();
    if (!data?.matchday) return true;
    const counts = [0, 0, 0, 0];
    for (const p of (data.nominated ?? []) as any[]) {
      const i = this.POS_INDEX[p.position];
      if (i !== undefined) counts[i]++;
    }
    return !this.VALID_FORMATIONS.some(f => f.every((v, i) => v === counts[i]));
  });

  logoFailed = false;

  constructor() {
    this.cache.ensureSeasons();
  }
}
