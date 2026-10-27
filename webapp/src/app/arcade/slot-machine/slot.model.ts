/** Symbole auf den Walzen (wie SlotTrait::slotConfig() im Backend); blank = Leerfeld */
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
  seven: 'Sieben', bell: 'Glocke', plum: 'Pflaume', orange: 'Orange', lemon: 'Zitrone', cherry: 'Kirsche', blank: 'Leer',
};
