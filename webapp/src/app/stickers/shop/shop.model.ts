// "Die Klebrigsten" — Shop: Packs gegen Lukaten (verdient im Bestico, Hauptliga) oder Euro (PayPal).

/**
 * Folienfarbe des Packs im Shop — bewusst andere Farben als die verdienten Packs (Rot/Gold/Blau/Graphit).
 * Lukaten: orange (#fa983a) / red (#eb2f06) / blue (#1e3799) · Euro: fire/leaf/sun/bordeaux/lilac.
 * Gleiche Farben wie beim Aufreißen (pack-open-dialog: pack--o-{offer key}).
 */
export type ShopFoil = 'orange' | 'red' | 'blue' | 'fire' | 'leaf' | 'sun' | 'bordeaux' | 'lilac';
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
  foil: ShopFoil;
  clubPick?: boolean;      // Vereins-Pack: nur Sticker eines gewählten Vereins
  once?: boolean;          // nur einmal pro Saison
  highlight?: string;      // Band, z.B. "Beliebt"
}

// Lukaten: jeder Manager startet pro Saison mit 100 → reicht für 3–6 Packs
export const LUKATEN_OFFERS: ShopOffer[] = [
  { key: 'l-small',  currency: 'lukaten', name: 'Kleines Pack', packs: 1, packSize: 3, guaranteedNew: 1, price: 15, foil: 'orange' },
  { key: 'l-big',    currency: 'lukaten', name: 'Großes Pack',  packs: 1, packSize: 6, guaranteedNew: 2, price: 25, foil: 'red', highlight: 'Beliebt' },
  { key: 'l-club',   currency: 'lukaten', name: 'Vereins-Pack', packs: 1, packSize: 5, guaranteedNew: 5, price: 40, foil: 'blue', clubPick: true },
];

// Euro (PayPal): Packs wie das Tages-Pack (3 Sticker, 1 garantiert neu); nichts unter 1,99 € wegen der PayPal-Gebühr
export const EUR_STARTER: ShopOffer =
  { key: 'e-starter', currency: 'eur', name: 'Starter', packs: 10, packSize: 3, guaranteedNew: 1, price: 1.99, foil: 'fire', once: true, highlight: 'Einmalig' };

export const EUR_OFFERS: ShopOffer[] = [
  { key: 'e-handful', currency: 'eur', name: 'Handvoll', packs: 5,  packSize: 3, guaranteedNew: 1, price: 2.99, foil: 'leaf' },
  // Staffel pro Sticker: Handvoll 0,20 € · Stapel 0,17 € (−17 %) · Kiste 0,13 € (−33 %)
  { key: 'e-stack',   currency: 'eur', name: 'Stapel',   packs: 10, packSize: 3, guaranteedNew: 1, price: 4.99, foil: 'sun', highlight: 'Beliebt' },
  { key: 'e-crate',   currency: 'eur', name: 'Kiste',    packs: 25, bonusPacks: 5, packSize: 3, guaranteedNew: 1, price: 9.99, foil: 'bordeaux', highlight: 'Bester Preis' },
  { key: 'e-club',    currency: 'eur', name: 'Vereins-Pack', packs: 1, packSize: 5, guaranteedNew: 5, price: 1.99, foil: 'lilac', clubPick: true },
];

/** Referenz für "X % günstiger": Preis pro Sticker der Handvoll. */
export const EUR_BASE_PER_STICKER = 2.99 / 15;

export function stickerCount(o: ShopOffer): number { return o.packs * o.packSize; }
