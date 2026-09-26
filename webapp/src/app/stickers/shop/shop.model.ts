// "Die Klebrigsten" — Shop: Lukaten (Wettwährung aus dem Bestico) gegen Sticker-Packs eintauschen.

/** Folienfarbe des Packs im Shop — bewusst andere Farben als die verdienten Packs (Rot/Gold/Blau). */
export type ShopFoil = 'teal' | 'violet' | 'onyx';

export interface ShopOffer {
  key: string;
  name: string;
  size: number;            // Sticker im Pack
  guaranteedNew: number;   // davon garantiert neu
  price: number;           // Lukaten
  foil: ShopFoil;
  highlight?: string;      // optionales Band, z.B. "Beliebt"
}

// PLATZHALTER — Konditionen stehen noch nicht fest
export const SHOP_OFFERS: ShopOffer[] = [
  { key: 'small',  name: 'Kleines Pack', size: 3,  guaranteedNew: 1,  price: 20, foil: 'teal' },
  { key: 'medium', name: 'Großes Pack',  size: 6,  guaranteedNew: 2,  price: 35, foil: 'violet', highlight: 'Beliebt' },
  { key: 'large',  name: 'Sammler-Box',  size: 10, guaranteedNew: 10, price: 75, foil: 'onyx' },
];
