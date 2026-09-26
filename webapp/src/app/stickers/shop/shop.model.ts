// "Die Klebrigsten" — Shop: Packs gegen Lukaten (verdient im Bestico, Hauptliga) oder Euro (PayPal).

/** Folienfarbe des Packs im Shop — bewusst andere Farben als die verdienten Packs (Rot/Gold/Blau). */
export type ShopFoil = 'teal' | 'violet' | 'onyx' | 'club';
export type ShopCurrency = 'lukaten' | 'eur';

export interface ShopOffer {
  key: string;
  currency: ShopCurrency;
  name: string;
  packs: number;           // Anzahl Packs
  bonusPacks?: number;     // davon gratis (z.B. "25 + 5")
  packSize: number;        // Sticker je Pack
  guaranteedNew: number;   // je Pack garantiert neu
  price: number;           // Lukaten bzw. Euro
  foil: ShopFoil;          // 'club' = Vereinsfarben des gewählten Vereins
  clubPick?: boolean;      // Vereins-Pack: nur Sticker eines gewählten Vereins
  once?: boolean;          // nur einmal pro Saison
  highlight?: string;      // Band, z.B. "Beliebt"
}

// Lukaten: jeder Manager startet pro Saison mit 100 → reicht für 3–6 Packs
export const LUKATEN_OFFERS: ShopOffer[] = [
  { key: 'l-small',  currency: 'lukaten', name: 'Kleines Pack', packs: 1, packSize: 3, guaranteedNew: 1, price: 15, foil: 'teal' },
  { key: 'l-big',    currency: 'lukaten', name: 'Großes Pack',  packs: 1, packSize: 6, guaranteedNew: 2, price: 25, foil: 'violet', highlight: 'Beliebt' },
  { key: 'l-club',   currency: 'lukaten', name: 'Vereins-Pack', packs: 1, packSize: 5, guaranteedNew: 5, price: 40, foil: 'club', clubPick: true },
];

// Euro (PayPal): Packs wie das Tages-Pack (3 Sticker, 1 garantiert neu); nichts unter 1,99 € wegen der PayPal-Gebühr
export const EUR_STARTER: ShopOffer =
  { key: 'e-starter', currency: 'eur', name: 'Starter', packs: 10, packSize: 3, guaranteedNew: 1, price: 1.99, foil: 'teal', once: true, highlight: 'Einmalig' };

export const EUR_OFFERS: ShopOffer[] = [
  { key: 'e-handful', currency: 'eur', name: 'Handvoll', packs: 5,  packSize: 3, guaranteedNew: 1, price: 2.99, foil: 'teal' },
  { key: 'e-stack',   currency: 'eur', name: 'Stapel',   packs: 12, packSize: 3, guaranteedNew: 1, price: 4.99, foil: 'violet', highlight: 'Beliebt' },
  { key: 'e-crate',   currency: 'eur', name: 'Kiste',    packs: 30, bonusPacks: 5, packSize: 3, guaranteedNew: 1, price: 9.99, foil: 'onyx', highlight: 'Bester Preis' },
  { key: 'e-club',    currency: 'eur', name: 'Vereins-Pack', packs: 1, packSize: 5, guaranteedNew: 5, price: 1.99, foil: 'club', clubPick: true },
];

/** Referenz für "X % günstiger": Preis pro Sticker der Handvoll. */
export const EUR_BASE_PER_STICKER = 2.99 / 15;

export function stickerCount(o: ShopOffer): number { return o.packs * o.packSize; }
