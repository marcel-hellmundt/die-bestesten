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
  seven: 'Pokal', bell: 'Medaille', plum: 'SdS', orange: 'Tor', lemon: 'Weiße Weste', cherry: 'Vorlage', blank: 'Bank',
};

/** Bild je Symbol (public/img/icons/) */
export const SYMBOL_ICONS: Record<SlotSymbol, string> = {
  seven: 'img/icons/trophy.png', bell: 'img/icons/medal.png', plum: 'img/icons/sds.png', orange: 'img/icons/goal.png',
  lemon: 'img/icons/clean_sheet.png', cherry: 'img/icons/assist.png', blank: 'img/icons/bench.png',
};
