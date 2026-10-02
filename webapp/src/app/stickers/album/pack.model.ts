// "Die Klebrigsten" — Packs: Metadaten fürs geschlossene Pack + aufgedeckte Karten.
import { STREAK_DAYS, StickerPack, StickerPackSource } from '../../core/sticker-status.service';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { DEFAULT_SHARED_PARAMS, Sticker } from './album.model';
import { PACK_KINDS, PackKind, kindFromOffer } from '../shop/shop.model';

/** Was vor dem Öffnen auf dem Pack steht (Art, Anlass, Anzahl). */
export interface PackInfo {
  id: string | null;             // null = Test-Pack (nur im Browser gewürfelt, nichts gespeichert)
  source: StickerPackSource;
  size: number;
  milestonePoints: number | null;
  matchdayNumber: number | null;
  leagueName: string | null;
  shopOffer?: string | null;     // Shop-Pack: gekauftes Angebot
  kind?: PackKind | null;        // Shop-Pack: Pack-Art (normal/big/club/special) — bestimmt Design + Inhalt
  clubId?: string | null;        // Vereins-Pack (nur Test-Packs brauchen das im Browser)
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
    kind: p.source === 'shop' ? ((p.pack_kind as PackKind | null) ?? kindFromOffer(p.shop_offer)) : null,
    clubId: p.club_id ?? null,
  };
}

/**
 * Sonder-Packs (Geburtstag, Weihnachten): Größe + garantiert neue Karten — müssen zu stickerConfig() im Backend
 * passen (birthday_/christmas_pack_size, *_guaranteed_new; vorläufige Werte).
 */
export const SPECIAL_PACKS: Record<'birthday' | 'christmas', { size: number; guaranteedNew: number; epicMin: number }> = {
  birthday:  { size: 5, guaranteedNew: 2, epicMin: 1 },   // epicMin = mind. so viele Karten "episch oder besser" (min_epic)
  christmas: { size: 5, guaranteedNew: 2, epicMin: 1 },
};

/** Streak-Pack (7 Tage in Folge online): Größe — muss zu stickerConfig() im Backend passen (streak_pack_size). */
export const STREAK_PACK_SIZE = 3;

/** Größe + Garantie je Pack-Art nach den echten Regeln (für Test-Packs und die Pack-Beschriftung). */
export function packRules(source: StickerPackSource): { size: number; allNew: boolean } {
  const r = DEFAULT_SHARED_PARAMS;
  switch (source) {
    case 'milestone':     return { size: r.milestonePackSize, allNew: r.milestoneAllNew };
    case 'matchday_best': return { size: r.bestPackSize, allNew: r.bestAllNew };
    case 'birthday':
    case 'christmas':     return { size: SPECIAL_PACKS[source].size, allNew: false };
    case 'streak':        return { size: STREAK_PACK_SIZE, allNew: false };
    default:              return { size: r.dailyPackSize, allNew: false };
  }
}

/** Ziehregeln eines Packs (Test-Packs im Browser, Beschriftung): garantiert neu, mind. Holo, mind. episch, nur ein Verein. */
export function packDrawRules(p: PackInfo): { size: number; guaranteed: number; holoMin: number; epicMin: number } {
  if (p.kind) {
    const k = PACK_KINDS[p.kind];
    return { size: k.size, guaranteed: k.guaranteedNew, holoMin: k.holoMin, epicMin: 0 };
  }
  if (p.source === 'birthday' || p.source === 'christmas') {
    const s = SPECIAL_PACKS[p.source];
    return { size: p.size, guaranteed: Math.min(s.guaranteedNew, p.size), holoMin: 0, epicMin: Math.min(s.epicMin, p.size) };
  }
  const r = packRules(p.source);
  return { size: p.size, guaranteed: r.allNew ? p.size : (DEFAULT_SHARED_PARAMS.guaranteeNew ? 1 : 0), holoMin: 0, epicMin: 0 };
}

/** Beschriftung der Pack-Vorderseite: kleine Art-Zeile + große Überschrift. */
export function packFace(p: PackInfo): { kind: string; headline: string } {
  switch (p.source) {
    case 'daily':         return { kind: 'Täglich', headline: 'Tages-Pack' };
    case 'milestone':     return { kind: 'Meilenstein', headline: p.milestonePoints ? `${p.milestonePoints} Punkte` : 'Meilenstein' };
    case 'matchday_best': return { kind: 'Spieltagssieger', headline: p.matchdayNumber ? `Spieltag ${p.matchdayNumber}` : 'Spieltagssieger' };
    case 'shop':          return { kind: 'Shop', headline: p.kind ? PACK_KINDS[p.kind].short : 'Shop-Pack' };
    case 'birthday':      return { kind: 'Sonder-Pack', headline: 'Geburtstag' };
    case 'christmas':     return { kind: 'Sonder-Pack', headline: 'Weihnachten' };
    case 'streak':        return { kind: 'Streak', headline: `${STREAK_DAYS} Tage` };
    default:              return { kind: 'Bonus', headline: 'Bonus-Pack' };
  }
}

/** Anzahl-Zeile: "3 Sticker", "5 neue Sticker" (alle garantiert neu), "3 Sticker · 1 Holo" bzw. "5 Sticker · 1 episch" */
export function packCountLabel(p: PackInfo): string {
  const r = packDrawRules(p);
  const base = `${p.size} ${r.guaranteed >= p.size ? 'neue ' : ''}Sticker`;
  if (r.holoMin > 0) return `${base} · ${r.holoMin} Holo`;
  return r.epicMin > 0 ? `${base} · ${r.epicMin} episch` : base;
}

/** Design-Schlüssel eines Packs: Pack-Art bei Shop-Packs, sonst die Quelle (für Farbe + Bild, siehe PACK_ART) */
export type PackDesign = PackKind | StickerPackSource;
export function packDesign(p: PackInfo): PackDesign {
  return p.source === 'shop' && p.kind ? p.kind : p.source;
}

/**
 * Optionale Bilder auf den Packs (freigestellte PNGs unter public/img/stickers/front/), nach unten links versetzt (Motive schauen nach rechts)
 * und per Blend-Mode in die Folie gemischt (Default normal = voll sichtbar). Ohne Eintrag: kein Bild. Abstimmen auf /klebrigsten/packs.
 * place 'center' = kleiner, mittig unten, nicht gedreht (z.B. Icons wie die Medaille).
 */
export interface PackArt { src: string; blend?: string; place?: 'corner' | 'center'; }
export const PACK_ART: Partial<Record<PackDesign, PackArt>> = {
  normal:  { src: 'img/stickers/front/dinosaur.png' },
  big:     { src: 'img/stickers/front/dragon.png' },
  club:    { src: 'img/stickers/front/phoenix.png' },
  special: { src: 'img/stickers/front/wizard.png' },
  // Spieltagssieger: Medaille wie die Karte "Spieltagssiege" in der Saisontabelle (/liga/tabelle), hier in höherer Auflösung
  matchday_best: { src: 'img/stickers/front/medal.png', place: 'center' },
  // Sonder-Packs: wie die Medaille mittig
  birthday:  { src: 'img/stickers/front/birthday.png', place: 'center' },
  christmas: { src: 'img/stickers/front/christmas.png', place: 'center' },
  streak:    { src: 'img/stickers/front/fire.png', place: 'center' },
};
