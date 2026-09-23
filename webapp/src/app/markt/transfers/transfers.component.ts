import { Component, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DirectDeal } from './direct-deal-card.component';
import { WindowOffersResponse } from './transfer-window-detail.component';

interface Transferwindow {
  id: string;
  matchday_id: string;
  start_date: string;
  end_date: string;
  bid_count: number | null; // null bei laufender/kommender Phase (geheim)
  deal_count: number;
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

  /** Anzeigeliste (neueste zuerst): von den kommenden Phasen nur die nächsten beiden; nr = fortlaufende Nummer in der Saison. */
  visibleWindows = computed(() => {
    const now = new Date();
    const numbered = this.windows().map((w, i) => ({ w, nr: i + 1 }));
    const upcoming = numbered
      .filter(({ w }) => new Date(w.start_date) > now)
      .sort((a, b) => a.w.start_date.localeCompare(b.w.start_date))
      .slice(2);
    const hidden = new Set(upcoming.map(({ w }) => w.id));
    return numbered.filter(({ w }) => !hidden.has(w.id)).reverse();
  });

  /** Aktuelle Phase: die gerade offene, sonst die zuletzt begonnene. */
  currentWindow = computed<Transferwindow | null>(() => {
    const now = new Date();
    return this.sortedWindows().find(w => this.isOpen(w))
      ?? this.sortedWindows().find(w => new Date(w.start_date) <= now)
      ?? null;
  });

  private dealsState = toSignal(
    toObservable(this.currentWindow).pipe(
      switchMap(w => w
        ? this.api.get<WindowOffersResponse>(`offer?transferwindow_id=${w.id}`).pipe(
            map(res => ({ deals: res.direct_deals ?? [], loading: false })),
            catchError(() => of({ deals: [] as DirectDeal[], loading: false }))
          )
        : of({ deals: [] as DirectDeal[], loading: false })
      )
    ),
    { initialValue: { deals: [] as DirectDeal[], loading: true } }
  );

  currentDeals = computed(() => this.dealsState().deals);
  dealsLoading = computed(() => this.dealsState().loading);

  isClosed(w: Transferwindow): boolean {
    return new Date(w.end_date) < new Date();
  }

  isOpen(w: Transferwindow): boolean {
    const now = new Date();
    return new Date(w.start_date) <= now && now < new Date(w.end_date);
  }

  /** Geschlossene Phasen (Gebote + Deals) und die offene Phase (nur Deals) haben eine Detailseite. */
  isClickable(w: Transferwindow): boolean {
    return this.isClosed(w) || this.isOpen(w);
  }

  windowLabel(w: Transferwindow): string {
    if (this.isClosed(w)) return 'Geschlossen';
    if (this.isOpen(w))   return 'Offen';
    return 'Öffnet bald';
  }

  selectWindow(w: Transferwindow): void {
    if (!this.isClickable(w)) return;
    this.router.navigate(['/markt/transferphasen', w.id]);
  }
}
