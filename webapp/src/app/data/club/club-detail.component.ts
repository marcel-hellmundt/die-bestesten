import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { Club } from '../../core/models/club.model';

@Component({
  selector: 'app-club-detail',
  standalone: false,
  templateUrl: './club-detail.component.html',
  styleUrl: './club-detail.component.scss'
})
export class ClubDetailComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);
  cache         = inject(DataCacheService);

  private id$ = this.route.paramMap.pipe(map(p => p.get('id')!));

  private clubState = toSignal(
    this.id$.pipe(
      switchMap(id => this.api.get<any>(`club/${id}`).pipe(
        map(data => ({ data: Club.from(data), loading: false, error: null as string | null })),
        startWith({ data: null as Club | null, loading: true, error: null as string | null }),
        catchError(() => of({ data: null as Club | null, loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  private seasonsState = toSignal(
    this.id$.pipe(
      switchMap(id => this.api.get<any[]>(`club_in_season?club_id=${id}`).pipe(
        map(data => ({ data, loading: false, error: null as string | null })),
        startWith({ data: [] as any[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as any[], loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  club         = computed(() => this.clubState()?.data ?? null);
  loading      = computed(() => this.clubState()?.loading ?? true);
  error        = computed(() => this.clubState()?.error ?? null);
  seasons      = computed(() => this.seasonsState()?.data ?? []);
  seasonsLoading = computed(() => this.seasonsState()?.loading ?? true);

  constructor() {
    this.cache.ensureSeasons();
  }
}
