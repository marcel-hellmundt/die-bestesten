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

/** Marktwert-Grenzen der Seltenheitsstufen: ein Sticker gehört zur höchsten Stufe, deren Grenze sein Marktwert übersteigt. */
export interface TierThresholds { rare: number; epic: number; legendary: number; }
export const DEFAULT_TIER_THRESHOLDS: TierThresholds = { rare: 1_000_000, epic: 2_500_000, legendary: 5_000_000 };

export function tierOf(price: number | null, t: TierThresholds = DEFAULT_TIER_THRESHOLDS): Tier {
  const p = price ?? MIN_PRICE;
  if (p > t.legendary) return 'legendary';
  if (p > t.epic) return 'epic';
  if (p > t.rare) return 'rare';
  return 'common';
}

export const TIERS: Tier[] = ['common', 'rare', 'epic', 'legendary'];
export const TIER_LABEL: Record<Tier, string> = { common: 'Häufig', rare: 'Selten', epic: 'Episch', legendary: 'Legendär' };

/** Marktwert-Spannen je Tier als Text, z.B. "1–2,5 Mio". */
export function tierRanges(t: TierThresholds): Record<Tier, string> {
  const mio = (p: number) => (p / 1e6).toLocaleString('de-DE', { maximumFractionDigits: 1 });
  return {
    common: `≤ ${mio(t.rare)} Mio`,
    rare: `${mio(t.rare)}–${mio(t.epic)} Mio`,
    epic: `${mio(t.epic)}–${mio(t.legendary)} Mio`,
    legendary: `> ${mio(t.legendary)} Mio`,
  };
}
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

// Echte Regeln — müssen zu StickerPackTrait::stickerConfig() im Backend passen (Test-Packs + Simulations-Start)
export const DEFAULT_SHARED_PARAMS: SharedParams = {
  dailyPackSize: 3,
  guaranteeNew: true,
  milestoneInterval: 100,
  milestonePackSize: 3,
  milestoneAllNew: true,
  bestPackSize: 5,
  bestAllNew: true,
  rarityAlpha: 0.8,
  holoSilverChance: 0.01,   // 1 %  → bei ~1.000 Stickern pro Saison ≈ 10 Holo Silber
  holoGoldChance: 0.001,    // 0,1 % → ≈ 1 Holo Gold
};

/**
 * Manager-Typen — Startwerte aus den echten Daten: Einlog-Tage der letzten 33 Tage (manager_session),
 * Saisonpunkte der Vorsaison (679–1.455 Pkt. → 20–43 Pkt./Spieltag), Spieltagssiege (vorläufig).
 */
export const DEFAULT_PROFILES: SimProfile[] = [
  { key: 'active',   label: 'Aktiv & stark',     loginChance: 1,    avgPoints: 43, bestChance: 0.15 },
  { key: 'average',  label: 'Durchschnitt',      loginChance: 0.9,  avgPoints: 33, bestChance: 0.08 },  // übers Jahr inkl. Ferien eher 90 %
  { key: 'inactive', label: 'Inaktiv & schwach', loginChance: 0.36, avgPoints: 20, bestChance: 0.03 },
];

export function paramsFor(shared: SharedParams, p: SimProfile): SimParams {
  return { ...shared, loginChance: p.loginChance, avgPoints: p.avgPoints, bestChance: p.bestChance };
}
