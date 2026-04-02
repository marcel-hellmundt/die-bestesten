import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-team-detail',
  standalone: false,
  templateUrl: './team-detail.component.html',
  styleUrl: './team-detail.component.scss'
})
export class TeamDetailComponent {
  private api = inject(ApiService);
  cache       = inject(DataCacheService);

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

  team    = computed(() => this.state()?.data ?? null);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error ?? null);

  logoFailed = false;

  constructor() {
    this.cache.ensureSeasons();
  }
}
