import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

@Component({
  selector: 'app-team-overview',
  standalone: false,
  templateUrl: './team-overview.component.html',
  styleUrl: './team-overview.component.scss'
})
export class TeamOverviewComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);

  private id$ = this.route.parent!.paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`team/${id}?include_ratings=1`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  ratings = computed(() => (this.state().data?.ratings ?? []) as any[]);
  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);

  totalPoints = computed(() => this.ratings().filter(r => !r.invalid).reduce((s: number, r: any) => s + Number(r.points), 0));
}
