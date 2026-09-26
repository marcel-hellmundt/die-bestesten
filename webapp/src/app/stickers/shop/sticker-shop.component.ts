import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { SHOP_OFFERS, ShopOffer } from './shop.model';

/**
 * Shop (/klebrigsten/shop): Lukaten aus dem Bestico gegen Sticker-Packs eintauschen.
 * Entwurf — Angebote sind Platzhalter, der Kauf selbst ist noch nicht angebunden.
 */
@Component({
  selector: 'app-sticker-shop',
  standalone: false,
  templateUrl: './sticker-shop.component.html',
  styleUrl: './sticker-shop.component.scss',
})
export class StickerShopComponent {
  private api = inject(ApiService);

  readonly offers = SHOP_OFFERS;

  // undefined = lädt, null = nicht verfügbar
  budget = toSignal(
    this.api.get<{ budget: number }>('h2h_prediction/budget').pipe(
      map(r => r.budget),
      catchError(() => of(null)),
    ),
  );
  loadingBudget = computed(() => this.budget() === undefined);

  /** Wie viele Lukaten noch fehlen (0 = leistbar). */
  missing(o: ShopOffer): number {
    const b = this.budget();
    return b == null ? o.price : Math.max(0, Math.ceil(o.price - b));
  }

  // ── Kauf bestätigen ──
  confirming = signal<ShopOffer | null>(null);
  /** Guthaben nach dem Kauf. */
  budgetAfter = computed(() => {
    const o = this.confirming(), b = this.budget();
    return o && b != null ? b - o.price : null;
  });

  @HostListener('document:keydown.escape')
  closeConfirm(): void { this.confirming.set(null); }

  formatLukaten(v: number | null | undefined): string {
    if (v == null) return '–';
    return Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ',');
  }

  guaranteeLabel(o: ShopOffer): string {
    if (o.guaranteedNew >= o.size) return 'alle garantiert neu';
    return o.guaranteedNew === 1 ? '1 garantiert neu' : `${o.guaranteedNew} garantiert neu`;
  }
}
