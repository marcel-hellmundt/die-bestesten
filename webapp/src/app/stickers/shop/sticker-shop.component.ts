import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { StickerStatusService } from '../../core/sticker-status.service';
import { ALBUM_SOURCE, StickerAlbumService } from '../album/sticker-album.service';
import { AlbumClub } from '../album/album.model';
import { EUR_BASE_PER_STICKER, EUR_OFFERS, EUR_STARTER, LUKATEN_OFFERS, ShopOffer, stickerCount } from './shop.model';

/** Response von GET /sticker/shop — bezahlt wird aus der Hauptliga (oberste Liga mit Sticker-Album). */
interface ShopState {
  league: { id: string; name: string } | null;
  budget: number | null;
}

interface ClubChoice {
  club: AlbumClub;
  logo: string;
  total: number;
  missing: number;
}

/**
 * Shop (/klebrigsten/shop): Packs gegen Lukaten (Hauptliga) oder Euro (PayPal).
 * Entwurf — der Kauf selbst ist noch nicht angebunden.
 */
@Component({
  selector: 'app-sticker-shop',
  standalone: false,
  templateUrl: './sticker-shop.component.html',
  styleUrl: './sticker-shop.component.scss',
  // Vereine + Sammlung fürs Vereins-Pack (welcher Verein, wie viele fehlen noch)
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album' }, StickerAlbumService],
})
export class StickerShopComponent {
  private api = inject(ApiService);
  private album = inject(StickerAlbumService);
  private status = inject(StickerStatusService);

  readonly lukatenOffers = LUKATEN_OFFERS;
  readonly eurOffers = EUR_OFFERS;
  readonly starter = EUR_STARTER;
  readonly stickerCount = stickerCount;

  // undefined = lädt, null = Fehler
  private shop = toSignal(this.api.get<ShopState>('sticker/shop').pipe(catchError(() => of(null))));
  loading = computed(() => this.shop() === undefined);
  league  = computed(() => this.shop()?.league ?? null);
  budget  = computed(() => this.shop()?.budget ?? null);

  /** Wie viele Lukaten noch fehlen (0 = leistbar). */
  missingLukaten(o: ShopOffer): number {
    const b = this.budget();
    return b == null ? o.price : Math.max(0, Math.ceil(o.price - b));
  }

  // ── Euro: Preis pro Sticker + Ersparnis gegenüber der Handvoll ──
  perSticker(o: ShopOffer): number { return o.price / stickerCount(o); }
  savingPct(o: ShopOffer): number {
    return Math.round((1 - this.perSticker(o) / EUR_BASE_PER_STICKER) * 100);
  }

  // ── Vereins-Pack: Vereine mit Fortschritt, fast komplette zuerst ──
  clubChoices = computed<ClubChoice[]>(() => {
    const col = this.album.collectionFrom(this.status.state()?.collection ?? []);
    const choices = this.album.rows().map(r => ({
      club: r.club,
      logo: this.album.clubLogoUrl(r.club),
      total: r.stickers.length,
      missing: r.stickers.filter(s => !col.counts[s.idx]).length,
    }));
    // komplette Vereine ans Ende (nicht wählbar), sonst am wenigsten fehlend zuerst
    return choices.sort((a, b) => (a.missing === 0 ? 1 : 0) - (b.missing === 0 ? 1 : 0) || a.missing - b.missing);
  });

  // ── Kauf bestätigen ──
  confirming = signal<ShopOffer | null>(null);
  pickedClubId = signal<string | null>(null);
  pickedClub = computed(() => this.clubChoices().find(c => c.club.id === this.pickedClubId()) ?? null);

  open(o: ShopOffer): void {
    this.pickedClubId.set(null);
    this.confirming.set(o);
  }

  @HostListener('document:keydown.escape')
  closeConfirm(): void { this.confirming.set(null); }

  /** Guthaben nach dem Kauf (nur Lukaten). */
  budgetAfter = computed(() => {
    const o = this.confirming(), b = this.budget();
    return o?.currency === 'lukaten' && b != null ? b - o.price : null;
  });

  /** Bis zu 3 Packs im Stapel, vorderstes zuletzt gerendert. */
  stackOf(o: ShopOffer): number[] {
    return Array.from({ length: Math.min(o.packs, 3) }, (_, i) => Math.min(o.packs, 3) - 1 - i);
  }

  clubLogo(club: AlbumClub): string { return this.album.clubLogoUrl(club); }

  /** Folienfarben des Vereins-Packs aus den Vereinsfarben (neutral, solange kein Verein gewählt ist). */
  clubFoil(club: AlbumClub | null): Record<string, string> {
    const base = club?.primary_color ?? '#4b5563';
    return {
      '--foil-light': `color-mix(in srgb, ${base} 55%, white)`,
      '--foil': base,
      '--foil-dark': `color-mix(in srgb, ${base} 40%, black)`,
    };
  }

  formatLukaten(v: number | null | undefined): string {
    if (v == null) return '–';
    return Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ',');
  }

  formatEur(v: number): string {
    return v.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
  }

  guaranteeLabel(o: ShopOffer): string {
    if (o.guaranteedNew >= o.packSize) return 'alle garantiert neu';
    return o.packs > 1 ? `je Pack ${o.guaranteedNew} garantiert neu` : `${o.guaranteedNew} garantiert neu`;
  }
}
