import { Component, DestroyRef, HostListener, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { EurPurchaseResult, StickerStatusService } from '../../core/sticker-status.service';
import { AuthService } from '../../auth/auth.service';
import { ALBUM_SOURCE, StickerAlbumService } from '../album/sticker-album.service';
import { AlbumClub } from '../album/album.model';
import { EUR_BASE_PER_STICKER, EUR_OFFERS, EUR_STARTER, LUKATEN_OFFERS, ShopOffer, stickerCount } from './shop.model';

/** Eigener, noch nicht bestätigter Euro-Kauf (bitte per PayPal bezahlen). */
interface EurPending {
  id: string;
  offer_key: string;
  amount_cents: number;
  code: string;
  created_at: string;
  paypal_url: string;
}

/** Response von GET /sticker/shop — Lukaten aus der Hauptliga (oberste Liga mit Sticker-Album) + Euro-Käufe. */
interface ShopState {
  league: { id: string; name: string } | null;
  budget: number | null;
  eur?: { available: boolean; paypal_me: string; starter_available: boolean; pending: EurPending[] };
}

/** Admin-Liste GET /sticker/shop/purchases. */
interface EurPurchaseRow {
  id: string;
  manager_id: string;
  manager_name: string;
  offer_key: string;
  offer_name: string;
  amount_cents: number;
  code: string;
  status: 'pending' | 'paid' | 'cancelled';
  created_at: string;
  handled_at: string | null;
  handled_by_name: string | null;
  packs_total: number;
  packs_opened: number;
}

const EUR_MAX_PENDING = 3; // wie im Backend (StickerShopEurTrait)

interface ClubChoice {
  club: AlbumClub;
  logo: string;
  total: number;
  missing: number;
}

/**
 * Shop (/klebrigsten/shop): Packs gegen Lukaten (Hauptliga, POST /sticker/shop/buy) oder Euro
 * (POST /sticker/shop/buy_eur: Packs sofort, Zahlung per PayPal.me mit Kauf-Code, Admin bestätigt/storniert —
 * bis dahin sind Karten daraus nicht tauschbar). Admins sehen unten alle Euro-Käufe der Saison.
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
  private auth = inject(AuthService);
  private album = inject(StickerAlbumService);
  private status = inject(StickerStatusService);

  readonly lukatenOffers = LUKATEN_OFFERS;
  readonly eurOffers = EUR_OFFERS;
  readonly starter = EUR_STARTER;
  readonly stickerCount = stickerCount;

  // undefined = lädt, null = Fehler — nach einem Kauf neu geladen
  private reloadTick = signal(0);
  private shop = toSignal(toObservable(this.reloadTick).pipe(
    switchMap(() => this.api.get<ShopState>('sticker/shop').pipe(catchError(() => of(null)))),
  ));
  loading = computed(() => this.shop() === undefined);
  league  = computed(() => this.shop()?.league ?? null);
  budget  = computed(() => this.shop()?.budget ?? null);

  // ── Euro ──
  eurAvailable     = computed(() => this.shop()?.eur?.available ?? false);
  starterAvailable = computed(() => this.shop()?.eur?.starter_available ?? false);
  eurPending       = computed(() => this.shop()?.eur?.pending ?? []);
  paypalMe         = computed(() => this.shop()?.eur?.paypal_me ?? '');
  /** Zu viele unbezahlte Käufe → erst bezahlen */
  eurBlocked       = computed(() => this.eurPending().length >= EUR_MAX_PENDING);

  offerName(key: string): string {
    return [...LUKATEN_OFFERS, EUR_STARTER, ...EUR_OFFERS].find(o => o.key === key)?.name ?? key;
  }

  copied = signal<string | null>(null);
  copy(text: string): void {
    navigator.clipboard?.writeText(text).then(() => {
      this.copied.set(text);
      setTimeout(() => this.copied.set(null), 1500);
    }).catch(() => {});
  }

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
    this.buyError.set(null);
    this.bought.set(false);
    this.eurResult.set(null);
    this.confirming.set(o);
  }

  @HostListener('document:keydown.escape')
  closeConfirm(): void {
    if (this.buying()) return;
    this.confirming.set(null);
    // erst jetzt die neuen Packs groß einblenden (beim Euro-Kauf zuerst Code + PayPal-Link)
    this.status.announcePaused.set(false);
  }

  constructor() {
    inject(DestroyRef).onDestroy(() => this.status.announcePaused.set(false));
  }

  // ── Kaufen ──
  buying = signal(false);
  buyError = signal<string | null>(null);
  bought = signal(false);
  /** Nach einem Euro-Kauf: Code + PayPal-Link zum Bezahlen */
  eurResult = signal<EurPurchaseResult | null>(null);

  canBuy = computed(() => {
    const o = this.confirming();
    if (!o || this.buying() || this.bought()) return false;
    if (o.clubPick && !this.pickedClubId()) return false;
    if (o.currency === 'eur') {
      return this.eurAvailable() && !this.eurBlocked() && (!o.once || this.starterAvailable());
    }
    return this.missingLukaten(o) === 0;
  });

  buy(): void {
    const o = this.confirming();
    if (!o || !this.canBuy()) return;
    this.buying.set(true);
    this.buyError.set(null);
    const clubId = o.clubPick ? this.pickedClubId() : null;
    const done = () => {
      this.buying.set(false);
      this.bought.set(true);
      this.reloadTick.update(n => n + 1);
    };
    const fail = (err: any) => {
      this.buying.set(false);
      this.status.announcePaused.set(false);
      this.buyError.set(err?.error?.message ?? 'Kauf fehlgeschlagen');
    };
    if (o.currency === 'eur') {
      // Einblendung der neuen Packs erst nach dem Bezahl-Dialog (Code + PayPal-Link zuerst)
      this.status.announcePaused.set(true);
      this.status.buyShopEur(o.key, clubId).subscribe({ next: r => { this.eurResult.set(r); done(); }, error: fail });
    } else {
      this.status.buyShopOffer(o.key, clubId).subscribe({ next: done, error: fail });
    }
  }

  // ── Admin: Euro-Käufe bestätigen/stornieren ──
  readonly isAdmin = this.auth.isAdmin();
  private adminTick = signal(0);
  private adminData = toSignal(toObservable(this.adminTick).pipe(
    switchMap(() => !this.isAdmin ? of(null)
      : this.api.get<{ available: boolean; purchases: EurPurchaseRow[] }>('sticker/shop/purchases').pipe(catchError(() => of(null)))),
  ));
  purchases = computed(() => this.adminData()?.purchases ?? []);
  openPurchases = computed(() => this.purchases().filter(p => p.status === 'pending').length);
  adminBusyId = signal<string | null>(null);
  cancelAskId = signal<string | null>(null);
  adminError = signal<string | null>(null);

  handlePurchase(p: EurPurchaseRow, action: 'confirm' | 'cancel'): void {
    // Stornieren löscht Packs + Karten → zweiter Klick zur Bestätigung
    if (action === 'cancel' && this.cancelAskId() !== p.id) { this.cancelAskId.set(p.id); return; }
    this.adminBusyId.set(p.id);
    this.adminError.set(null);
    this.api.patch(`sticker/shop/purchases/${p.id}`, { action }).subscribe({
      next: () => { this.adminBusyId.set(null); this.cancelAskId.set(null); this.adminTick.update(n => n + 1); },
      error: err => {
        this.adminBusyId.set(null);
        this.adminError.set(err?.error?.message ?? 'Das hat nicht geklappt');
        this.adminTick.update(n => n + 1);
      },
    });
  }

  readonly purchaseStatusLabel: Record<string, string> = { pending: 'offen', paid: 'bezahlt', cancelled: 'storniert' };

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
