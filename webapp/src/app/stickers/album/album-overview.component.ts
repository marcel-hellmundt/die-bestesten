import {
  Component, DestroyRef, ElementRef, afterRenderEffect, computed, inject, input, output, signal, viewChild,
} from '@angular/core';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { AlbumClub, DEFAULT_SHARED_PARAMS, Sticker, TIERS, TIER_LABEL, isLight } from './album.model';
import { Collection, StickerAlbumService } from './sticker-album.service';
import { stickerWeights } from '../sticker-sim';

interface RarestCard {
  sticker: Sticker;
  card: StickerCardData;
  label: string;          // Seltenheit (+ Holo-Variante)
  odds: string;           // Chance, sie zu ziehen: "1 : 123.456"
  percent: string;        // dieselbe Chance in Prozent: "0,00081 %"
}

/** Prozent mit 2 signifikanten Stellen, auch bei sehr kleinen Werten (0,00081 %) */
function formatPercent(p: number): string {
  if (!(p > 0)) return '0 %';
  return p.toLocaleString('de-DE', { maximumSignificantDigits: 2 }) + ' %';
}

/**
 * Erste Seite des Sammelalbums: Gesamtfortschritt, Holo-/Doppelte-Zähler, zuletzt eingeklebt (24 h) + seltenste
 * Karte, Vereins-Kacheln.
 */
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
      tiers: TIERS.map(t => ({ key: t, label: TIER_LABEL[t], ...tiers[t] })),
    };
  });

  clubTiles = computed(() => {
    const col = this.collection();
    return this.rows().map((r, i) => {
      const have = r.stickers.filter(s => col.counts[s.idx] > 0).length;
      const color = r.club.primary_color ?? '#8b929e';
      return {
        index: i, club: r.club, have, total: r.stickers.length, logo: this.album.clubLogoUrl(r.club),
        // Kachel eines kompletten Vereins in Vereinsfarbe: Schrift weiß, bei (fast) weißer Vereinsfarbe schwarz
        color, ink: isLight(color) ? '#000' : '#fff',
      };
    });
  });

  /**
   * Zuletzt eingeklebt: alle in den letzten 24 Stunden neu eingeklebten Sticker (Erstzug), neueste zuerst —
   * leer (Abschnitt entfällt), wenn in der Zeit nichts Neues dazukam.
   */
  recent = computed<{ sticker: Sticker; card: StickerCardData }[]>(() => {
    const col = this.collection();
    const since = Date.now() - 24 * 60 * 60 * 1000;
    return this.album.stickers()
      .filter(s => col.firstAt[s.idx] >= since)
      .sort((a, b) => col.firstAt[b.idx] - col.firstAt[a.idx] || b.idx - a.idx)
      .map(s => ({ sticker: s, card: this.album.cardData(s, col.holo[s.idx]) }));
  });

  /** Reihe "Zuletzt eingeklebt": rechts noch Karten außerhalb des sichtbaren Bereichs → Verlauf einblenden */
  private recentRow = viewChild<ElementRef<HTMLElement>>('recentRow');
  recentMore = signal(false);

  updateRecentMore(): void {
    const el = this.recentRow()?.nativeElement;
    this.recentMore.set(!!el && el.scrollLeft + el.clientWidth < el.scrollWidth - 2);
  }

  constructor() {
    // nach jedem Neuzeichnen der Reihe (andere Karten, Größenänderung) neu prüfen
    afterRenderEffect(() => { this.recent(); this.recentRow(); this.updateRecentMore(); });
    const onResize = () => this.updateRecentMore();
    window.addEventListener('resize', onResize);
    inject(DestroyRef).onDestroy(() => window.removeEventListener('resize', onResize));
  }

  /**
   * Seltenste Karte der Sammlung: kleinste Zieh-Wahrscheinlichkeit = Gewicht des Stickers (Marktwert^-α, wie beim
   * Öffnen) × Chance der besten eigenen Variante (Holo Gold/Silber) — eine Holo-Karte schlägt so fast immer die
   * Normalen; null ohne Sammlung.
   */
  rarest = computed<RarestCard | null>(() => {
    const col = this.collection();
    const stickers = this.album.stickers();
    const rules = DEFAULT_SHARED_PARAMS;
    const weights = stickerWeights(stickers.map(s => s.price), rules.rarityAlpha);
    let best: Sticker | null = null;
    let bestScore = Infinity;
    for (const s of stickers) {
      if (!col.counts[s.idx]) continue;
      const holo = col.holo[s.idx];
      const variant = holo === 'gold' ? rules.holoGoldChance : holo === 'silver' ? rules.holoSilverChance : 1;
      const score = weights[s.idx] * variant;
      if (score < bestScore) { bestScore = score; best = s; }
    }
    if (!best) return null;
    const holo = col.holo[best.idx];
    const label = [TIER_LABEL[best.tier], holo === 'gold' ? 'Holo Gold' : holo === 'silver' ? 'Holo Silber' : null]
      .filter(Boolean).join(' · ');
    // Chance, dass ein gezogener Sticker genau diese Karte in dieser Variante ist (normal: ohne Holo-Anteil)
    const variant = holo === 'gold' ? rules.holoGoldChance
      : holo === 'silver' ? rules.holoSilverChance
      : 1 - rules.holoSilverChance - rules.holoGoldChance;
    const chance = weights[best.idx] * variant;
    return {
      sticker: best,
      card: this.album.cardData(best, holo),
      label,
      odds: chance > 0 ? '1 : ' + Math.round(1 / chance).toLocaleString('de-DE') : '—',
      percent: formatPercent(chance * 100),
    };
  });
}
