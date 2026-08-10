import { Component, computed, inject } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';
import { catchError, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-h2h-mode',
  standalone: false,
  templateUrl: './h2h-mode.component.html',
  styleUrl: './h2h-mode.component.scss',
})
export class H2HModeComponent {
  private route = inject(ActivatedRoute);
  private api   = inject(ApiService);
  cache         = inject(DataCacheService);

  private queryParamSeasonId = toSignal(
    this.route.queryParamMap.pipe(map(params => params.get('season_id'))),
    { initialValue: null as string | null },
  );

  // Falls back to the most recently started season when opened without a season_id
  // (e.g. bookmarked link), matching the default the H2H overview page itself uses.
  private latestStartedSeasonId = computed(() => {
    const sorted = [...this.cache.startedSeasons()].sort((a, b) => b.start_date.localeCompare(a.start_date));
    return sorted[0]?.id ?? null;
  });

  seasonId = computed(() => this.queryParamSeasonId() ?? this.latestStartedSeasonId());

  seasonLabel = computed(() => {
    const id = this.seasonId();
    return id ? this.cache.seasonName(id) : '';
  });

  private state = toSignal(
    toObservable(this.seasonId).pipe(
      filter((id): id is string => !!id),
      switchMap(id =>
        this.api.get<any>(`h2h?season_id=${id}`).pipe(
          map(data => ({ groupCount: (data?.groups ?? []).length, loading: false, error: null as string | null })),
          startWith({ groupCount: 0, loading: true, error: null as string | null }),
          catchError(() => of({ groupCount: 0, loading: false, error: 'Fehler beim Laden' })),
        )
      ),
    ),
    { initialValue: { groupCount: 0, loading: true, error: null as string | null } },
  );

  loading           = computed(() => this.state().loading);
  error             = computed(() => this.state().error);
  groupCount        = computed(() => this.state().groupCount);
  tournamentExists  = computed(() => this.groupCount() > 0);
  format            = computed<'9' | '12' | 'unknown'>(() => {
    const n = this.groupCount();
    if (n === 3) return '9';
    if (n === 4) return '12';
    return 'unknown';
  });
  teamCount = computed(() => this.groupCount() * 3);

  groupLabels = computed(() => {
    const n = this.groupCount();
    return Array.from({ length: n }, (_, i) => `Gruppe ${String.fromCharCode(65 + i)}`);
  });

  // Snake-seeding table: row per draft round, one placement rank per group column.
  // Odd rows (0-indexed 1, 3, ...) run right-to-left, matching the actual seeding order.
  snakeRows = computed(() => {
    const n = this.groupCount();
    if (n !== 3 && n !== 4) return [];
    const rows: { round: string; placements: number[] }[] = [];
    for (let round = 0; round < 3; round++) {
      const reversed = round % 2 === 1;
      const placements = Array.from({ length: n }, (_, gi) =>
        reversed ? round * n + (n - gi) : round * n + gi + 1
      );
      rows.push({ round: `${round + 1} ${reversed ? '←' : '→'}`, placements });
    }
    return rows;
  });

  constructor() {
    this.cache.ensureSeasons();
  }
}
