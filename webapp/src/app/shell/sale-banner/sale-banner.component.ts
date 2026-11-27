import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { StickerSale, StickerStatusService } from '../../core/sticker-status.service';
import { MatchdaySummaryService } from '../../core/matchday-summary.service';

const DISMISSED_KEY = 'sale_banner_dismissed';

/** Banner auf diesem Gerät schon weggeklickt? (auch für den UI-Test in der Verwaltung) */
export function isSaleBannerDismissed(): boolean {
  try { return localStorage.getItem(DISMISSED_KEY) === '1'; } catch { return false; }
}

/** UI-Test: Markierung löschen — beim nächsten echten Sale erscheint das Banner wieder (nur dieses Gerät). */
export function resetSaleBannerDismissed(): void {
  try { localStorage.removeItem(DISMISSED_KEY); } catch { /* egal */ }
}

/**
 * Nacht-Sale "Peitschig" (Sa + So 2–3 Uhr, Rabatt auf Sticker-Packs im Shop): wer die Seite in diesem Fenster
 * zum ersten Mal aufruft, sieht einmal groß diesen Hinweis mit Countdown bis 3 Uhr. Weggeklickt (oder zum Shop
 * gegangen) kommt er nie wieder — gemerkt im Browser (localStorage, je Gerät). Danach weist nur noch die Card
 * oben im Shop auf den Sale hin. Nur für Manager mit Sticker-Album; wartet, solange eine andere Einblendung
 * (Spieltags-Zusammenfassung, neue Packs) offen ist.
 */
@Component({
  selector: 'app-sale-banner',
  standalone: false,
  templateUrl: './sale-banner.component.html',
  styleUrl: './sale-banner.component.scss',
})
export class SaleBannerComponent {
  private stickers = inject(StickerStatusService);
  private summary = inject(MatchdaySummaryService);
  private router = inject(Router);

  private dismissed = signal(isSaleBannerDismissed());

  private preview = this.stickers.saleBannerPreview;

  /** Fenster für den Countdown — in der Admin-Vorschau ohne laufenden Sale: bis in einer Stunde */
  readonly sale = computed<StickerSale | null>(() => {
    const s = this.stickers.sale();
    if (!this.preview() || this.stickers.saleActive()) return s;
    return { active: true, starts_at: new Date().toISOString(), ends_at: new Date(Date.now() + 3600_000).toISOString(),
             lukaten_pct: s?.lukaten_pct ?? 10, eur_pct: s?.eur_pct ?? 20 };
  });

  visible = computed(() => this.preview() || (
    !this.dismissed()
    && this.stickers.enabled()
    && this.stickers.saleActive()
    && !this.stickers.salePreview() // Shop-Vorschau der Admins löst das echte Banner nicht aus
    && this.stickers.tabVisible()
    && !this.stickers.announcing()
    && !this.summary.blocking()));

  @HostListener('document:keydown.escape')
  onEscape(): void { if (this.visible()) this.dismiss(); }

  dismiss(): void {
    // Vorschau: nur schließen, nichts merken
    if (this.preview()) { this.preview.set(false); return; }
    this.dismissed.set(true);
    try { localStorage.setItem(DISMISSED_KEY, '1'); } catch { /* privates Fenster o.ä. — dann eben nur für diese Sitzung */ }
  }

  goShop(): void {
    this.dismiss();
    this.router.navigateByUrl('/klebrigsten/shop');
  }
}
