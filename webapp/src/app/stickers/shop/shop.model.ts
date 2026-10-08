// "Die Klebrigsten" — Shop: feste Pack-Arten, einzeln gegen Lukaten (Lukaten-Konto, siehe /lukaten) oder als
// Euro-Kombinationen (PayPal.me). Muss zu StickerShopTrait::stickerPackKinds()/…Offers(),
// LukatenAccountTrait::lukatenAccountConfig() (pack_prices) und StickerShopEurTrait::stickerShopEurOffers() im
// Backend passen (dort sind die Preise maßgeblich).

export type PackKind = 'normal' | 'big' | 'club' | 'special';
export type ShopCurrency = 'lukaten' | 'eur';

export interface PackKindDef {
  kind: PackKind;
  name: string;           // "Big Pack"
  short: string;          // Aufdruck auf dem Pack, z.B. "Big"
  size: number;           // Sticker
  guaranteedNew: number;  // davon garantiert neu
  holoMin: number;        // davon mindestens Holo
  club: boolean;          // nur Sticker eines gewählten Vereins
}

/** Die festen Pack-Arten — Farbe je Art: normal Grün #6aba49, big Koralle #f26d53, club Gelb #fcc732, special Lila #8854d0. */
export const PACK_KINDS: Record<PackKind, PackKindDef> = {
  normal:  { kind: 'normal',  name: 'Normales Pack', short: 'Normal',  size: 3, guaranteedNew: 1, holoMin: 0, club: false },
  big:     { kind: 'big',     name: 'Big Pack',      short: 'Big',     size: 7, guaranteedNew: 2, holoMin: 0, club: false },
  club:    { kind: 'club',    name: 'Vereins-Pack',  short: 'Verein',  size: 5, guaranteedNew: 5, holoMin: 0, club: true },
  special: { kind: 'special', name: 'Special Pack',  short: 'Special', size: 3, guaranteedNew: 1, holoMin: 1, club: false },
};
export const PACK_KIND_ORDER: PackKind[] = ['normal', 'big', 'club', 'special'];

export interface ShopOffer {
  key: string;
  currency: ShopCurrency;
  name: string;
  contents: Partial<Record<PackKind, number>>;  // Pack-Art → Anzahl
  price: number;          // Lukaten bzw. Euro
  once?: boolean;         // nur einmal pro Saison
  highlight?: string;     // Band, z.B. "Beliebt"
}

// Lukaten: je eine Pack-Art. Maßstab ist die Arbeit eines Spieltags (rund 500 Einträge = 500 Lukaten = 5 normale
// Packs); direkt gegen Euro sind Packs bewusst günstiger als über gekaufte Lukaten (Vergleich: /verwaltung/ui-tests, Preise).
// Keys stabil, l-small = Normales Pack. Der Shop zeigt die Preise des Servers (GET /sticker/shop → prices);
// die Werte hier sind der Rückfall und die Grundlage der Simulation.
export const LUKATEN_OFFERS: ShopOffer[] = [
  { key: 'l-small',   currency: 'lukaten', name: PACK_KINDS.normal.name,  contents: { normal: 1 },  price: 100 },
  { key: 'l-big',     currency: 'lukaten', name: PACK_KINDS.big.name,     contents: { big: 1 },     price: 200, highlight: 'Beliebt' },
  { key: 'l-club',    currency: 'lukaten', name: PACK_KINDS.club.name,    contents: { club: 1 },    price: 350 },
  { key: 'l-special', currency: 'lukaten', name: PACK_KINDS.special.name, contents: { special: 1 }, price: 350 },
];

// Euro: Kombinationen — Staffel 2,99 → 4,99 → 6,99; Starter einmalig als Einstieg
export const EUR_STARTER: ShopOffer =
  { key: 'e-starter', currency: 'eur', name: 'Starter', contents: { normal: 10 }, price: 1.99, once: true, highlight: 'Einmalig' };

export const EUR_BUNDLES: ShopOffer[] = [
  { key: 'e-handful', currency: 'eur', name: 'Handvoll', contents: { normal: 5 },         price: 2.99 },
  { key: 'e-stack',   currency: 'eur', name: 'Stapel',   contents: { normal: 4, big: 3 }, price: 4.99, highlight: 'Beliebt' },
  { key: 'e-crate',   currency: 'eur', name: 'Kiste',    contents: { normal: 3, big: 3, special: 1, club: 1 }, price: 6.99, highlight: 'Bester Wert' },
];

/** einzelne Packs gegen Euro (Impulskauf) */
export const EUR_SINGLES: ShopOffer[] = [
  { key: 'e-club',    currency: 'eur', name: PACK_KINDS.club.name,    contents: { club: 1 },    price: 1.99 },
  { key: 'e-special', currency: 'eur', name: PACK_KINDS.special.name, contents: { special: 1 }, price: 1.99 },
];

export const EUR_OFFERS: ShopOffer[] = [...EUR_BUNDLES, ...EUR_SINGLES];
export const ALL_SHOP_OFFERS: ShopOffer[] = [...LUKATEN_OFFERS, EUR_STARTER, ...EUR_OFFERS];

/**
 * "Wert" je Pack-Art in Euro für die Ersparnis-Anzeige: Normal wie in der Handvoll (2,99 € / 5),
 * Big nach Stickern (7 statt 3), Vereins-/Special-Pack = Einzelpreis.
 */
const EUR_KIND_VALUE: Record<PackKind, number> = { normal: 0.6, big: 1.4, club: 1.99, special: 1.99 };

/** [{kind, count}] in fester Reihenfolge */
export function offerKinds(o: ShopOffer): { kind: PackKind; count: number }[] {
  return PACK_KIND_ORDER.filter(k => (o.contents[k] ?? 0) > 0).map(k => ({ kind: k, count: o.contents[k]! }));
}
export function packsOf(o: ShopOffer): number { return offerKinds(o).reduce((n, x) => n + x.count, 0); }
export function stickerCount(o: ShopOffer): number {
  return offerKinds(o).reduce((n, x) => n + x.count * PACK_KINDS[x.kind].size, 0);
}
/** enthält ein Vereins-Pack → beim Kauf Verein wählen */
export function hasClub(o: ShopOffer): boolean { return (o.contents.club ?? 0) > 0; }
/** besteht nur aus Vereins-Packs (für den Kaufplan der Simulation) */
export function isClubOnly(o: ShopOffer): boolean { return offerKinds(o).every(x => x.kind === 'club'); }
/** Summe der Einzelwerte in Euro */
export function offerValueEur(o: ShopOffer): number {
  return offerKinds(o).reduce((v, x) => v + x.count * EUR_KIND_VALUE[x.kind], 0);
}
/** z.B. "4 Normal + 3 Big" */
export function contentsLabel(o: ShopOffer): string {
  return offerKinds(o).map(x => `${x.count} ${PACK_KINDS[x.kind].short}`).join(' + ');
}
/** alte Käufe ohne pack_kind: Pack-Art aus dem Angebot herleiten */
export function kindFromOffer(key: string | null | undefined): PackKind | null {
  if (!key) return null;
  if (key === 'l-small') return 'normal';
  if (key === 'l-big') return 'big';
  if (key === 'l-club' || key === 'e-club') return 'club';
  if (key === 'l-special' || key === 'e-special') return 'special';
  return key.startsWith('e-') ? 'normal' : null;
}
