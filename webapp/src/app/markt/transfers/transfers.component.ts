import { Component, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

interface Transferwindow {
  id: string;
  matchday_id: string;
  start_date: string;
  end_date: string;
}

@Component({
  selector: 'app-transfers',
  standalone: false,
  templateUrl: './transfers.component.html',
  styleUrl: './transfers.component.scss',
})
export class TransfersComponent {
  private api    = inject(ApiService);
  private router = inject(Router);

  private activeSeason$ = this.api.get<{ id: string }>('season/active').pipe(
    catchError(() => of(null))
  );

  windows = toSignal(
    this.activeSeason$.pipe(
      switchMap(s => s
        ? this.api.get<Transferwindow[]>(`transferwindow?season_id=${s.id}`).pipe(catchError(() => of([])))
        : of([])
      )
    ),
    { initialValue: [] as Transferwindow[] }
  );

  sortedWindows = computed(() => [...this.windows()].reverse());

  isClosed(w: Transferwindow): boolean {
    return new Date(w.end_date) < new Date();
  }

  selectWindow(w: Transferwindow): void {
    if (!this.isClosed(w)) return;
    this.router.navigate(['/markt/transferphasen', w.id]);
  }
}
