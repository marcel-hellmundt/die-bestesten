import { Component, computed, inject, input, output } from '@angular/core';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { AlbumClub, Sticker, TIERS, TIER_LABEL } from './album.model';
import { Collection, StickerAlbumService } from './sticker-album.service';

/** Erste Seite des Sammelalbums: Gesamtfortschritt, Holo-/Doppelte-Zähler, Vereins-Kacheln, zuletzt eingeklebt. */
@Component({
  selector: 'app-album-overview',
  standalone: false,
  templateUrl: './album-overview.component.html',
  styleUrl: './album-overview.component.scss',
})
export class AlbumOverviewComponent {
  private album = inject(StickerAlbumService);

  rows = input.required<{ club: AlbumClub; stickers: Sticker[] }[]>();
  collection = input.required<Collection>();
  /** Besitzer des angezeigten Albums — null = eigenes Album */
  ownerName = input<string | null>(null);
  goTo = output<number>();          // Index der Vereinsseite (0-basiert)
  open = output<Sticker>();

  totals = computed(() => {
    const col = this.collection();
    const stickers = this.album.stickers();
    let have = 0, pulled = 0;
    const tiers = Object.fromEntries(TIERS.map(t => [t, { have: 0, total: 0 }])) as Record<string, { have: number; total: number }>;
    for (const s of stickers) {
      const c = col.counts[s.idx];
      pulled += c;
      tiers[s.tier].total++;
      if (c > 0) { have++; tiers[s.tier].have++; }
    }
    const clubsComplete = this.rows().filter(r => r.stickers.every(s => col.counts[s.idx] > 0)).length;
    return {
      have, total: stickers.length, pulled,
      duplicates: pulled - have,
      pct: stickers.length ? have / stickers.length : 0,
      clubsComplete,
      tiers: TIERS.map(t => ({ label: TIER_LABEL[t], ...tiers[t] })),
    };
  });

  clubTiles = computed(() => {
    const col = this.collection();
    return this.rows().map((r, i) => {
      const have = r.stickers.filter(s => col.counts[s.idx] > 0).length;
      return { index: i, club: r.club, have, total: r.stickers.length, logo: this.album.clubLogoUrl(r.club) };
    });
  });

  /** Zuletzt eingeklebt: die jüngsten Erstzüge (stabile Kartendaten je Sammelstand). */
  recent = computed<{ sticker: Sticker; card: StickerCardData }[]>(() => {
    const col = this.collection();
    return this.album.stickers()
      .filter(s => col.firstAt[s.idx] >= 0)
      .sort((a, b) => col.firstAt[b.idx] - col.firstAt[a.idx] || b.idx - a.idx)
      .slice(0, 8)
      .map(s => ({ sticker: s, card: this.album.cardData(s, col.holo[s.idx]) }));
  });
}
