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
  loading   = computed(() => this.state().loading);
  error     = computed(() => this.state().error);

  positionLabel(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'TW', DEFENDER: 'ABW', MIDFIELDER: 'MF', FORWARD: 'ST',
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

  photoUrl(p: any): string | null {
    if (!p.photo_uploaded || !p.season_id) return null;
    return `https://img.die-bestesten.de/img/player/${p.season_id}/${p.id}.png`;
  }

  photoErrors = new Set<string>();
  onPhotoError(id: string) { this.photoErrors.add(id); }
}
