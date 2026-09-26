// "Die Klebrigsten" — Packs: Metadaten fürs geschlossene Pack + aufgedeckte Karten.
import { StickerPack, StickerPackSource } from '../../core/sticker-status.service';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { DEFAULT_SHARED_PARAMS, Sticker } from './album.model';
import { EUR_OFFERS, EUR_STARTER, LUKATEN_OFFERS } from '../shop/shop.model';

const SHOP_OFFERS_ALL = [...LUKATEN_OFFERS, EUR_STARTER, ...EUR_OFFERS];

/** Was vor dem Öffnen auf dem Pack steht (Art, Anlass, Anzahl). */
export interface PackInfo {
  id: string | null;             // null = Test-Pack (nur im Browser gewürfelt, nichts gespeichert)
  source: StickerPackSource;
  size: number;
  milestonePoints: number | null;
  matchdayNumber: number | null;
  leagueName: string | null;
  shopOffer?: string | null;     // Shop-Pack: gekauftes Angebot
}

export interface PackCard {
  sticker: Sticker;
  card: StickerCardData;
  isNew: boolean;
  count: number;   // Anzahl nach diesem Pack (inkl. Doppelter)
}

export function packInfo(p: StickerPack): PackInfo {
  return {
    id: p.id, source: p.source, size: p.size,
    milestonePoints: p.milestone_points, matchdayNumber: p.matchday_number, leagueName: p.league_name,
    shopOffer: p.shop_offer ?? null,
  };
}

/** Größe + Garantie je Pack-Art nach den echten Regeln (für Test-Packs und die Pack-Beschriftung). */
export function packRules(source: StickerPackSource): { size: number; allNew: boolean } {
  const r = DEFAULT_SHARED_PARAMS;
  switch (source) {
    case 'milestone':     return { size: r.milestonePackSize, allNew: r.milestoneAllNew };
    case 'matchday_best': return { size: r.bestPackSize, allNew: r.bestAllNew };
    default:              return { size: r.dailyPackSize, allNew: false };
  }
}

/** Beschriftung der Pack-Vorderseite: kleine Art-Zeile + große Überschrift. */
export function packFace(p: PackInfo): { kind: string; headline: string } {
  switch (p.source) {
    case 'daily':         return { kind: 'Täglich', headline: 'Tages-Pack' };
    case 'milestone':     return { kind: 'Meilenstein', headline: p.milestonePoints ? `${p.milestonePoints} Punkte` : 'Meilenstein' };
    case 'matchday_best': return { kind: 'Spieltagssieger', headline: p.matchdayNumber ? `Spieltag ${p.matchdayNumber}` : 'Spieltagssieger' };
    case 'shop':          return { kind: 'Shop', headline: SHOP_OFFERS_ALL.find(o => o.key === p.shopOffer)?.name ?? 'Shop-Pack' };
    default:              return { kind: 'Bonus', headline: 'Bonus-Pack' };
  }
}
