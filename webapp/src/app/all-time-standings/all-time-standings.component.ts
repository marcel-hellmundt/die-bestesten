import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../core/api.service';

interface AllTimeStandingsEntry {
  id: string;
  manager_name: string;
  alias: string | null;
  total_points: number;
}

@Component({
  selector: 'app-all-time-standings',
  standalone: false,
  templateUrl: './all-time-standings.component.html',
  styleUrl: './all-time-standings.component.scss'
})
export class AllTimeStandingsComponent {
  private api = inject(ApiService);

  private state = toSignal(
    this.api.get<AllTimeStandingsEntry[]>('all_time_standings').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: [] as AllTimeStandingsEntry[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as AllTimeStandingsEntry[], loading: false, error: 'Fehler beim Laden' }))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);

  avatarFailed = new Set<string>();

  onAvatarError(id: string): void {
    this.avatarFailed.add(id);
  }
}
