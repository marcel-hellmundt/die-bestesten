import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

@Component({
  selector: 'app-h2h-match',
  standalone: false,
  templateUrl: './h2h-match.component.html',
  styleUrl: './h2h-match.component.scss',
})
export class H2HMatchComponent {
  private api = inject(ApiService);

  private id$ = inject(ActivatedRoute).paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`h2h/${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' })),
        )
      )
    )
  );

  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error ?? null);
  data    = computed(() => this.state()?.data ?? null);

  match      = computed(() => this.data()?.match ?? null);
  matchday   = computed(() => this.data()?.matchday ?? null);
  homeTeam   = computed(() => this.data()?.home_team ?? null);
  awayTeam   = computed(() => this.data()?.away_team ?? null);
  homeRating = computed(() => this.data()?.home_rating ?? null);
  awayRating = computed(() => this.data()?.away_rating ?? null);
  homeLineup = computed(() => (this.data()?.home_lineup ?? []) as any[]);
  awayLineup = computed(() => (this.data()?.away_lineup ?? []) as any[]);
  homeBench  = computed(() => (this.data()?.home_bench  ?? []) as any[]);
  awayBench  = computed(() => (this.data()?.away_bench  ?? []) as any[]);

  homeGoalscorers = computed(() =>
    this.homeLineup().filter(p => p.goals > 0).flatMap(p => Array(p.goals).fill(p))
  );
  awayGoalscorers = computed(() =>
    this.awayLineup().filter(p => p.goals > 0).flatMap(p => Array(p.goals).fill(p))
  );

  homeSdsDefenders = computed(() =>
    this.homeLineup().filter(p => p.position === 'DEFENDER' && p.sds > 0)
  );
  awaySdsDefenders = computed(() =>
    this.awayLineup().filter(p => p.position === 'DEFENDER' && p.sds > 0)
  );

  phaseLabel = computed(() => {
    const map: Record<string, string> = {
      group: 'Gruppenphase', quarterfinal: 'Viertelfinale', semifinal: 'Halbfinale', final: 'Finale',
    };
    return map[this.match()?.phase ?? ''] ?? '';
  });

  showBench = false;

  homePhotoUrl(p: any): string {
    return `https://img.die-bestesten.de/img/player/${p.photo_season_id}/${p.player_id}.png`;
  }
}
