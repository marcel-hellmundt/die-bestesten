import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

@Component({
  selector: 'app-lineup',
  standalone: false,
  templateUrl: './lineup.component.html',
  styleUrl: './lineup.component.scss'
})
export class LineupComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);

  private teamId$ = this.route.parent!.paramMap.pipe(map(p => p.get('id')!));

  selectedMatchdayId = signal<string | null>(
    this.route.snapshot.queryParamMap.get('matchday_id')
  );

  private state = toSignal(
    combineLatest([
      this.teamId$,
      toObservable(this.selectedMatchdayId),
    ]).pipe(
      switchMap(([teamId, matchdayId]) => {
        const url = matchdayId
          ? `team_lineup?team_id=${teamId}&matchday_id=${matchdayId}`
          : `team_lineup?team_id=${teamId}`;
        return this.api.get<any>(url).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        );
      })
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  matchday  = computed(() => this.state().data?.matchday  ?? null);
  matchdays = computed(() => (this.state().data?.matchdays ?? []) as any[]);
  nominated = computed(() => (this.state().data?.nominated ?? []) as any[]);
  bench     = computed(() => (this.state().data?.bench     ?? []) as any[]);
  points    = computed(() => this.state().data?.points    ?? null);
  maxPoints = computed(() => this.state().data?.max_points ?? null);
  loading   = computed(() => this.state().loading);
  error     = computed(() => this.state().error);

  formation = computed(() => {
    const n = this.nominated();
    return [
      n.filter(p => p.position === 'GOALKEEPER').length,
      n.filter(p => p.position === 'DEFENDER').length,
      n.filter(p => p.position === 'MIDFIELDER').length,
      n.filter(p => p.position === 'FORWARD').length,
    ];
  });

  readonly validFormations = [
    [1,3,4,3],[1,3,5,2],[1,4,3,3],[1,4,4,2],[1,4,5,1],[1,5,3,2],[1,5,4,1],
  ];

  readonly pitchPositions = ['FORWARD', 'MIDFIELDER', 'DEFENDER', 'GOALKEEPER'];

  getPlayersByPosition(pos: string): any[] {
    return this.nominated().filter(p => p.position === pos);
  }

  formationLabel(f: number[]): string {
    return `${f[1]}${f[2]}${f[3]}`;
  }

  isFormationActive(f: number[]): boolean {
    const cur = this.formation();
    return f[0] === cur[0] && f[1] === cur[1] && f[2] === cur[2] && f[3] === cur[3];
  }

  pointsPercent(): number {
    const p = this.points(), max = this.maxPoints();
    if (!max || max <= 0) return 0;
    return Math.min(100, Math.round((p / max) * 100));
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

  gradeInt(grade: any): number {
    return Math.round(+grade * 10);
  }

  participationLabel(p: string | null): string {
    if (p === 'starting')   return 'Startelf';
    if (p === 'substitute') return 'Eingewechselt';
    return 'Kein Einsatz';
  }

  participationClass(p: string | null): string {
    if (p === 'starting')   return 'bench-player__status--starting';
    if (p === 'substitute') return 'bench-player__status--sub';
    return 'bench-player__status--none';
  }

  photoUrl(p: any): string | null {
    if (!p.photo_uploaded || !p.season_id) return null;
    return `https://img.die-bestesten.de/img/player/${p.season_id}/${p.id}.png`;
  }

  photoErrors = new Set<string>();
  onPhotoError(id: string) { this.photoErrors.add(id); }
}
