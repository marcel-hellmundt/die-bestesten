/**
 * Symbole auf den Walzen — die Schlüssel wie SlotTrait::slotConfig() im Backend (klassische Automaten-Namen),
 * angezeigt als Fußball-Icons (SYMBOL_ICONS); blank = Leerfeld, zeigt die Bank
 */
export type SlotSymbol = 'seven' | 'bell' | 'plum' | 'orange' | 'lemon' | 'cherry' | 'blank';

/** Eine Zeile der Gewinntabelle: drei gleiche, drei beliebige aus symbols, oder die ersten beiden von links */
export interface SlotPayline {
  key: string;
  match: 'three' | 'any3' | 'left2';
  symbols: SlotSymbol[];
  multiplier: number;
}

export interface SlotWin {
  key: string;
  multiplier: number;
  payout: number;
  jackpot: boolean;
}

export interface SlotSpinRow {
  stops: number[];
  symbols: SlotSymbol[];
  stake: number;
  win_key: string | null;
  payout: number;
  created_at: string;
}

/** Response von GET /slot */
export interface SlotState {
  ready: boolean;
  balance: number | null;
  stakes: number[];
  reels: SlotSymbol[][];
  paytable: SlotPayline[];
  jackpot: { key: string; stake: number; payout: number };
  recent: SlotSpinRow[];
  stats: { spins: number; staked: number; won: number };
}

/** Response von POST /slot/spin */
export interface SlotSpinResult {
  stops: number[];
  symbols: SlotSymbol[];
  win: SlotWin | null;
  payout: number;
  balance: number | null;
  test: boolean; // Test-Dreh eines Admins: nichts gebucht
}

export const SYMBOL_NAMES: Record<SlotSymbol, string> = {
  seven: 'Stern', bell: 'Kleeblatt', plum: 'Tor', orange: 'Vorlage', lemon: 'Weiße Weste', cherry: 'Geld', blank: 'Bank',
};

/**
 * Bild je Symbol: public/img/slot/{Schlüssel}.png — eigener Ordner, damit die Bilder unabhängig von den übrigen
 * Icons (z.B. in höherer Auflösung) ersetzt werden können; Dateiname = Symbol-Schlüssel
 */
export const SYMBOL_ICONS = Object.fromEntries(
  (['seven', 'bell', 'plum', 'orange', 'lemon', 'cherry', 'blank'] as SlotSymbol[]).map(s => [s, `img/slot/${s}.png`]),
) as Record<SlotSymbol, string>;
