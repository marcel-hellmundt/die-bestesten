import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../../core/api.service';

@Component({
  selector: 'app-data-season',
  standalone: false,
  templateUrl: './season.component.html',
  styleUrl: './season.component.scss'
})
export class SeasonDataComponent {
  private api = inject(ApiService);

  private state = toSignal(
    this.api.get<any[]>('season').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: [] as any[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as any[], loading: false, error: 'Fehler beim Laden' }))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);
}
