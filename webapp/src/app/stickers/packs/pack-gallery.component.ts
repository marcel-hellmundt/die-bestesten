import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { StickerCardData, requestTiltPermission } from '../sticker-card/sticker-card.component';
import { PACK_ART, PackCard, PackDesign, PackInfo, packRules } from '../album/pack.model';
import { PackOpener } from '../album/pack-opener';
import { ALBUM_SOURCE, StickerAlbumService } from '../album/sticker-album.service';
import { LUKATEN_OFFERS, PACK_KINDS, PACK_KIND_ORDER } from '../shop/shop.model';

/**
 * Admin-Testseite /klebrigsten/packs: alle Pack-Designs nebeneinander (Tages-Pack, Meilenstein, Spieltagssieger,
 * Bonus + die Shop-Arten normal/big/club/special) — zum Aufreißen wie echt, aber nur im Browser gewürfelt,
 * nichts wird gespeichert. Eigene Bilder auf den Packs: PACK_ART in album/pack.model.ts.
 */
@Component({
  selector: 'app-pack-gallery',
  standalone: false,
  templateUrl: './pack-gallery.component.html',
  styleUrl: './pack-gallery.component.scss',
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album' }, StickerAlbumService, PackOpener],
})
export class PackGalleryComponent {
  opener = inject(PackOpener);
  private album = inject(StickerAlbumService);

  loading = this.album.loading;
  error = this.album.error;
  rows = this.album.rows;
  openCard = signal<StickerCardData | null>(null);

  /** ein Pack je Design — jedes mit eigenem Anlass, damit keins gestapelt wird */
  private packs = computed<PackInfo[]>(() => {
    const clubs = this.rows().map(r => r.club.id);
    const clubId = clubs[Math.floor(Math.random() * clubs.length)] ?? null;
    const base = { id: null, milestonePoints: null, matchdayNumber: null, leagueName: null };
    const earned: PackInfo[] = [
      { ...base, source: 'daily', size: packRules('daily').size },
      { ...base, source: 'milestone', size: packRules('milestone').size, milestonePoints: 500, leagueName: 'Test-Liga' },
      { ...base, source: 'matchday_best', size: packRules('matchday_best').size, matchdayNumber: 12, leagueName: 'Test-Liga' },
      { ...base, source: 'admin', size: packRules('admin').size },
    ];
    const shop: PackInfo[] = PACK_KIND_ORDER.map(kind => ({
      ...base, source: 'shop', kind, size: PACK_KINDS[kind].size,
      shopOffer: LUKATEN_OFFERS.find(o => o.contents[kind])?.key ?? null,
      clubId: PACK_KINDS[kind].club ? clubId : null,
    }));
    return [...earned, ...shop];
  });

  /** Designs mit eigenem Bild (PACK_ART) — zur Kontrolle unter der Bühne */
  readonly artList = Object.entries(PACK_ART) as [PackDesign, { src: string; blend?: string }][];

  constructor() {
    // sobald das Album da ist (Karten zum Würfeln): alle Packs zur Auswahl
    effect(() => {
      if (!this.loading() && this.rows().length) untracked(() => this.reset());
    });
  }

  /** nach "Fertig", "Nächstes Pack" oder "Später öffnen" wieder alle Packs zeigen */
  reset(): void {
    this.opener.showChoice(this.packs());
  }

  pick(index: number): void {
    requestTiltPermission(); // synchron in der Klick-Geste (iOS)
    this.opener.pick(index);
  }

  openPulled(c: PackCard): void {
    requestTiltPermission();
    this.openCard.set(c.card);
  }
}
