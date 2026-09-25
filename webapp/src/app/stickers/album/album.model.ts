// "Die Klebrigsten" — gemeinsames Album-Modell für Sammelalbum und Simulation.
import { MIN_PRICE, SimParams, SimProfile } from '../sticker-sim';

export type Tier = 'common' | 'rare' | 'epic' | 'legendary';
/** Spieler-Sticker oder einer der beiden Vereins-Sticker (Wappen/Stadion) je Club. */
export type StickerKind = 'player' | 'logo' | 'stadium';

export interface AlbumPlayer {
  id: string; displayname: string; first_name: string | null; last_name: string | null;
  position: string | null; price: number | null; photo_uploaded: boolean;
}

export interface AlbumClub {
  id: string; name: string; short_name: string; logo_uploaded: boolean;
  primary_color: string | null; secondary_color: string | null;
  stadium_name: string | null;
  sticker_price?: number;  // nur /sticker/album: eingefrorener Gewichtungs-Marktwert der Vereins-Sticker
  players: AlbumPlayer[];
}

/** Response von GET /sticker/album (eingefroren) bzw. /sticker/album_preview (live). */
export interface AlbumPreview {
  season_id: string | null;
  cutoff_date: string | null;
  matchdays: { number: number; kickoff_date: string }[];
  clubs: AlbumClub[];
}

/** Sticker im flachen Album (idx = Position in weights/counts der Simulation). */
export interface Sticker extends AlbumPlayer {
  idx: number;
  clubIdx: number;
  kind: StickerKind;
  tier: Tier;
}

/**
 * Vereins-Sticker (Wappen/Stadion) werden intern wie ein Spieler mit fiktivem Marktwert gewichtet, abhängig vom
 * Vorsaison-Tabellenplatz (= Album-Reihenfolge): Erster wie 3 Mio (etwas seltener), Letzter wie 0,5 Mio, linear dazwischen.
 */
export const CLUB_STICKER_PRICE_BEST = 3_000_000;
export const CLUB_STICKER_PRICE_WORST = 500_000;

export function clubStickerPrice(rank: number, clubCount: number): number {
  if (clubCount <= 1) return CLUB_STICKER_PRICE_BEST;
  const t = Math.min(Math.max(rank / (clubCount - 1), 0), 1);
  return Math.round(CLUB_STICKER_PRICE_BEST - t * (CLUB_STICKER_PRICE_BEST - CLUB_STICKER_PRICE_WORST));
}

export function tierOf(price: number | null): Tier {
  const p = price ?? MIN_PRICE;
  if (p > 5_000_000) return 'legendary';
  if (p > 2_500_000) return 'epic';
  if (p > 1_000_000) return 'rare';
  return 'common';
}

export const TIERS: Tier[] = ['common', 'rare', 'epic', 'legendary'];
export const TIER_LABEL: Record<Tier, string> = { common: 'Häufig', rare: 'Selten', epic: 'Episch', legendary: 'Legendär' };
// Marktwert-Spannen je Tier — müssen zu tierOf() passen
export const TIER_RANGE: Record<Tier, string> = {
  common: '≤ 1 Mio', rare: '1–2,5 Mio', epic: '2,5–5 Mio', legendary: '> 5 Mio',
};
export const POSITION_LABEL: Record<string, string> = { GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU' };
export const POSITION_ORDER = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];

/** Initialen für leere Slots: "Michael Gregoritsch" → "M.G."; Fallback aus dem Anzeigenamen. */
export function initials(s: Pick<Sticker, 'first_name' | 'last_name' | 'displayname'>): string {
  const parts = s.first_name && s.last_name
    ? [s.first_name, s.last_name]
    : s.displayname.replace(/\./g, ' ').split(/\s+/).filter(Boolean);
  return parts.slice(0, 3).map(p => p.charAt(0).toUpperCase() + '.').join('');
}

// ── Standard-Regeln (Simulation + Demo-Sammlung im Album) ─────────────────────
/** Regeln, die für alle Manager gleich sind — der Rest von SimParams kommt aus dem Profil. */
export type SharedParams = Omit<SimParams, 'loginChance' | 'avgPoints' | 'bestChance'>;

export const DEFAULT_SHARED_PARAMS: SharedParams = {
  dailyPackSize: 3,
  guaranteeNew: true,
  milestoneInterval: 100,
  milestonePackSize: 3,
  milestoneAllNew: false,
  bestPackSize: 3,
  bestAllNew: false,
  rarityAlpha: 0.5,
  holoSilverChance: 0.01,   // 1 %  → bei ~1.000 Stickern pro Saison ≈ 10 Holo Silber
  holoGoldChance: 0.001,    // 0,1 % → ≈ 1 Holo Gold
};

/**
 * Manager-Typen — Startwerte aus den echten Daten: Einlog-Tage der letzten 33 Tage (manager_session),
 * Saisonpunkte der Vorsaison (679–1.455 Pkt. → 20–43 Pkt./Spieltag), Spieltagssiege (vorläufig).
 */
export const DEFAULT_PROFILES: SimProfile[] = [
  { key: 'active',   label: 'Aktiv & stark',     loginChance: 1,    avgPoints: 43, bestChance: 0.15 },
  { key: 'average',  label: 'Durchschnitt',      loginChance: 0.95, avgPoints: 33, bestChance: 0.08 },
  { key: 'inactive', label: 'Inaktiv & schwach', loginChance: 0.36, avgPoints: 20, bestChance: 0.03 },
];

export function paramsFor(shared: SharedParams, p: SimProfile): SimParams {
  return { ...shared, loginChance: p.loginChance, avgPoints: p.avgPoints, bestChance: p.bestChance };
}
