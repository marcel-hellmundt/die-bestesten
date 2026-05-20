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

  homeSdsDefenders = computed(() => {
    const count = this.homeRating()?.sds_defender ?? 0;
    const named = this.homeLineup().filter(p =>
      (p.position === 'DEFENDER' || p.position === 'GOALKEEPER') && p.sds
    );
    return named.length >= count
      ? named.slice(0, count)
      : [...named, ...Array(count - named.length).fill(null)];
  });
  awaySdsDefenders = computed(() => {
    const count = this.awayRating()?.sds_defender ?? 0;
    const named = this.awayLineup().filter(p =>
      (p.position === 'DEFENDER' || p.position === 'GOALKEEPER') && p.sds
    );
    return named.length >= count
      ? named.slice(0, count)
      : [...named, ...Array(count - named.length).fill(null)];
  });

  private assistBlocks(lineup: any[]): string[][] {
    const flat = lineup.filter(p => p.assists > 0).flatMap(p => Array(p.assists).fill(p.displayname));
    const blocks: string[][] = [];
    for (let i = 0; i + 2 < flat.length; i += 3) blocks.push(flat.slice(i, i + 3));
    return blocks;
  }

  homeGoalEvents = computed(() => [
    ...this.homeLineup().filter(p => p.goals > 0).flatMap(p =>
      Array(p.goals).fill({ type: 'goal' as const, label: p.displayname })
    ),
    ...this.assistBlocks(this.homeLineup()).map(b => ({ type: 'assist' as const, label: b.join(', ') })),
  ]);
  awayGoalEvents = computed(() => [
    ...this.awayLineup().filter(p => p.goals > 0).flatMap(p =>
      Array(p.goals).fill({ type: 'goal' as const, label: p.displayname })
    ),
    ...this.assistBlocks(this.awayLineup()).map(b => ({ type: 'assist' as const, label: b.join(', ') })),
  ]);

  awayGoalsBlocked = computed(() =>
    Math.min(this.homeSdsDefenders().length, this.awayGoalEvents().length)
  );
  homeGoalsBlocked = computed(() =>
    Math.min(this.awaySdsDefenders().length, this.homeGoalEvents().length)
  );

  positionLabel(pos: string): string {
    return ({ GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU' } as any)[pos] ?? pos;
  }
  positionColor(pos: string): string {
    return ({ GOALKEEPER: 'var(--position-goalkeeper)', DEFENDER: 'var(--position-defender)', MIDFIELDER: 'var(--position-midfielder)', FORWARD: 'var(--position-forward)' } as any)[pos] ?? 'transparent';
  }
  managerPhotoUrl(managerId: string): string {
    return `https://img.die-bestesten.de/img/manager/${managerId}.jpg`;
  }

  phaseLabel = computed(() => {
    const map: Record<string, string> = {
      group: 'Gruppenphase', quarterfinal: 'Viertelfinale', semifinal: 'Halbfinale', final: 'Finale',
    };
    return map[this.match()?.phase ?? ''] ?? '';
  });

  showBench = false;

  homeLogoUrl = computed(() => {
    const t = this.homeTeam();
    return t?.id && t?.season_id ? `https://img.die-bestesten.de/img/team/${t.season_id}/${t.id}.png` : null;
  });

  awayLogoUrl = computed(() => {
    const t = this.awayTeam();
    return t?.id && t?.season_id ? `https://img.die-bestesten.de/img/team/${t.season_id}/${t.id}.png` : null;
  });

  homePhotoUrl(p: any): string {
    return `https://img.die-bestesten.de/img/player/${p.photo_season_id}/${p.player_id}.png`;
  }
}
