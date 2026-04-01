import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../core/api.service';
import { DataCacheService } from '../core/data-cache.service';

@Component({
  selector: 'app-manager-detail',
  standalone: false,
  templateUrl: './manager-detail.component.html',
  styleUrl: './manager-detail.component.scss'
})
export class ManagerDetailComponent {
  private api   = inject(ApiService);
  cache         = inject(DataCacheService);

  private id$ = inject(ActivatedRoute).paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`manager/${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    )
  );

  manager       = computed(() => this.state()?.data ?? null);
  loading       = computed(() => this.state()?.loading ?? true);
  error         = computed(() => this.state()?.error ?? null);
  teams         = computed(() => this.manager()?.teams ?? []);
  totalPoints   = computed(() => this.teams().reduce((s: number, t: any) => s + Number(t.total_points), 0));

  avatarFailed = false;

  constructor() {
    this.cache.ensureSeasons();
  }
}
