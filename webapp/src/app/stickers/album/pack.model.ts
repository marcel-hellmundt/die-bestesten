// "Die Klebrigsten" — Packs: Metadaten fürs geschlossene Pack + aufgedeckte Karten.
import { StickerPack, StickerPackSource } from '../../core/sticker-status.service';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { Sticker } from './album.model';

/** Was vor dem Öffnen auf dem Pack steht (Art, Anlass, Anzahl). */
export interface PackInfo {
  id: string | null;             // null = Test-Pack (nur im Browser gewürfelt, nichts gespeichert)
  source: StickerPackSource;
  size: number;
  milestonePoints: number | null;
  matchdayNumber: number | null;
  leagueName: string | null;
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
  };
}

/** Beschriftung der Pack-Vorderseite: kleine Art-Zeile + große Überschrift. */
export function packFace(p: PackInfo): { kind: string; headline: string } {
  switch (p.source) {
    case 'daily':         return { kind: 'Täglich', headline: 'Tages-Pack' };
    case 'milestone':     return { kind: 'Meilenstein', headline: p.milestonePoints ? `${p.milestonePoints} Punkte` : 'Meilenstein' };
    case 'matchday_best': return { kind: 'Spieltagssieger', headline: p.matchdayNumber ? `Spieltag ${p.matchdayNumber}` : 'Spieltagssieger' };
    default:              return { kind: 'Bonus', headline: 'Bonus-Pack' };
  }
}
