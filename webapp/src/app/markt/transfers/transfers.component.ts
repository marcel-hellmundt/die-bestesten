import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

interface Transferwindow {
  id: string;
  matchday_id: string;
  start_date: string;
  end_date: string;
}

interface Bid {
  id: string;
  team_id: string;
  team_name: string | null;
  offer_value: number;
  price_snapshot: number | null;
  status: 'success' | 'lost' | 'cancelled' | 'pending';
  created_at: string;
}

interface PlayerOffers {
  player_id: string;
  displayname: string | null;
  position: 'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD' | null;
  bids: Bid[];
}

interface WindowOffersResponse {
  window: Transferwindow;
  offers: PlayerOffers[];
}

@Component({
  selector: 'app-transfers',
  standalone: false,
  templateUrl: './transfers.component.html',
  styleUrl: './transfers.component.scss',
})
export class TransfersComponent {
  private api = inject(ApiService);

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

  selectedWindowId = signal<string | null>(null);
  offersLoading    = signal(false);
  offersError      = signal<string | null>(null);
  windowOffers     = signal<WindowOffersResponse | null>(null);

  readonly positionLabel: Record<string, string> = {
    GOALKEEPER: 'TW',
    DEFENDER:   'AV',
    MIDFIELDER: 'MF',
    FORWARD:    'ST',
  };

  isClosed(w: Transferwindow): boolean {
    return new Date(w.end_date) < new Date();
  }

  selectWindow(w: Transferwindow): void {
    if (!this.isClosed(w) || this.selectedWindowId() === w.id) return;
    this.selectedWindowId.set(w.id);
    this.windowOffers.set(null);
    this.offersError.set(null);
    this.offersLoading.set(true);
    this.api.get<WindowOffersResponse>(`offer?transferwindow_id=${w.id}`).subscribe({
      next: data => {
        this.windowOffers.set(data);
        this.offersLoading.set(false);
      },
      error: err => {
        this.offersError.set(err?.error?.message ?? 'Fehler beim Laden');
        this.offersLoading.set(false);
      },
    });
  }

  formatPrice(value: number): string {
    return value.toLocaleString('de-DE') + ' €';
  }

}
