import { Component, computed, inject } from '@angular/core';
import { PACK_ART, PackArt, PackDesign, PackInfo, packRules } from '../album/pack.model';
import { ALBUM_SOURCE, StickerAlbumService } from '../album/sticker-album.service';
import { LUKATEN_OFFERS, PACK_KINDS, PACK_KIND_ORDER } from '../shop/shop.model';

/**
 * Admin-Testseite /klebrigsten/packs: alle Pack-Designs, gruppiert in Event-Packs (Tages-Pack, Meilenstein,
 * Spieltagssieger) und Shop-Packs (normal/big/club/special) — je eine Bühne zum Aufreißen wie echt, aber nur
 * im Browser gewürfelt, nichts wird gespeichert. Eigene Bilder auf den Packs: PACK_ART in album/pack.model.ts.
 */
@Component({
  selector: 'app-pack-gallery',
  standalone: false,
  templateUrl: './pack-gallery.component.html',
  styleUrl: './pack-gallery.component.scss',
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album' }, StickerAlbumService],
})
export class PackGalleryComponent {
  private album = inject(StickerAlbumService);

  loading = this.album.loading;
  error = this.album.error;
  rows = this.album.rows;

  private base = { id: null, milestonePoints: null, matchdayNumber: null, leagueName: null };

  /** jedes mit eigenem Anlass, damit keins gestapelt wird */
  eventPacks = computed<PackInfo[]>(() => [
    { ...this.base, source: 'daily', size: packRules('daily').size },
    { ...this.base, source: 'milestone', size: packRules('milestone').size, milestonePoints: 500, leagueName: 'Test-Liga' },
    { ...this.base, source: 'matchday_best', size: packRules('matchday_best').size, matchdayNumber: 12, leagueName: 'Test-Liga' },
  ]);

  /** Shop-Packs je Pack-Art; Vereins-Pack mit zufälligem Verein aus dem Album */
  shopPacks = computed<PackInfo[]>(() => {
    const clubs = this.rows().map(r => r.club.id);
    const clubId = clubs[Math.floor(Math.random() * clubs.length)] ?? null;
    return PACK_KIND_ORDER.map(kind => ({
      ...this.base, source: 'shop' as const, kind, size: PACK_KINDS[kind].size,
      shopOffer: LUKATEN_OFFERS.find(o => o.contents[kind])?.key ?? null,
      clubId: PACK_KINDS[kind].club ? clubId : null,
    }));
  });

  /** Designs mit eigenem Bild (PACK_ART) — zur Kontrolle unter den Bühnen */
  readonly artList = Object.entries(PACK_ART) as [PackDesign, PackArt][];
}
