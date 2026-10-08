import { Component, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import {
  EUR_OFFERS, EUR_STARTER, LUKATEN_OFFERS, PACK_KINDS, PACK_KIND_ORDER, PackKind, contentsLabel, offerKinds, stickerCount,
} from '../../stickers/shop/shop.model';

/** Lukaten gegen Euro: ein Bündel (GET /lukaten/account → rules.eur_bundles) */
interface EurBundle {
  key: string;
  amount_cents: number;
  lukaten: number;
}

interface AccountRules {
  packs: { kind: string; price: number }[];
  eur_bundles: EurBundle[];
}

interface Finding {
  level: 'ok' | 'warn' | 'error';
  text: string;
}

/** Ein ganzer Spieltag Einträge, grob — Bezugsgröße für "was ist die Arbeit eines Spieltags wert" */
const ENTRIES_PER_MATCHDAY = 500;
/** darunter gilt der Vorteil des direkten Euro-Kaufs als zu klein, um aufzufallen */
const LOW_BONUS = 0.2;

/** Preise werden auf Zehner gerundet, wie sie in der Config stehen */
function scale(value: number, factor: number): number {
  return Math.max(10, Math.round((value * factor) / 10) * 10);
}

/**
 * Test "Preise" unter /verwaltung/ui-tests: das Preis-Dreieck Euro → Lukaten → Sticker gegen Euro → Sticker,
 * alles auf Lukaten normiert, damit Ungereimtheiten auffallen. Drei Wege gibt es: Lukaten gegen Euro (Bündel),
 * Packs gegen Lukaten, Packs direkt gegen Euro. Der direkte Euro-Kauf soll sich sichtbar mehr lohnen als der Umweg
 * über gekaufte Lukaten — der Vorteil ("Bonus") steht je Angebot als Balken und Prozentwert da, die Liste
 * "Auffälligkeiten" prüft die Staffelungen. Zwei Regler probieren aus, was eine gleichmäßige Anhebung der
 * Pack-Preise bzw. der Lukaten je Bündel bewirkt. Liest nur; geändert werden die Werte in der API-Config
 * (LukatenAccountTrait::lukatenAccountConfig(), StickerShopEurTrait::stickerShopEurOffers()).
 */
@Component({
  selector: 'app-price-triangle',
  standalone: false,
  templateUrl: './price-triangle.component.html',
  styleUrl: './price-triangle.component.scss',
})
export class PriceTriangleComponent {
  private api = inject(ApiService);

  /** Preise der API — undefined = lädt, null = nicht verfügbar */
  private rules = signal<AccountRules | null | undefined>(undefined);
  loading = computed(() => this.rules() === undefined);
  unavailable = computed(() => this.rules() === null);

  /** Was-wäre-wenn: Pack-Preise in Lukaten bzw. Lukaten je Bündel mal diesem Faktor */
  packFactor = signal(1);
  bundleFactor = signal(1);
  changed = computed(() => this.packFactor() !== 1 || this.bundleFactor() !== 1);

  readonly entriesPerMatchday = ENTRIES_PER_MATCHDAY;

  constructor() {
    this.api.get<{ ready: boolean; rules?: AccountRules }>('lukaten/account').subscribe({
      next: a => this.rules.set(a.ready && a.rules?.eur_bundles?.length ? a.rules : null),
      error: () => this.rules.set(null),
    });
  }

  reset(): void {
    this.packFactor.set(1);
    this.bundleFactor.set(1);
  }

  // ── Lukaten → Sticker: Pack-Arten ──
  kinds = computed(() => {
    const byKind = new Map((this.rules()?.packs ?? []).map(p => [p.kind, p.price]));
    return PACK_KIND_ORDER.map(kind => {
      const def = PACK_KINDS[kind];
      const base = byKind.get(kind) ?? LUKATEN_OFFERS.find(o => o.contents[kind])?.price ?? 0;
      const price = scale(base, this.packFactor());
      return { kind, name: def.name, size: def.size, guaranteedNew: def.guaranteedNew, holoMin: def.holoMin, base, price, perSticker: price / def.size };
    });
  });

  private price = computed(() =>
    Object.fromEntries(this.kinds().map(k => [k.kind, k.price])) as Record<PackKind, number>);

  // ── Euro → Lukaten: Bündel ──
  bundles = computed(() => {
    const list = [...(this.rules()?.eur_bundles ?? [])].sort((a, b) => a.amount_cents - b.amount_cents);
    const rows = list.map(b => {
      const lukaten = scale(b.lukaten, this.bundleFactor());
      const eur = b.amount_cents / 100;
      return { key: b.key, eur, base: b.lukaten, lukaten, perEuro: lukaten / eur, centPer: b.amount_cents / lukaten };
    });
    return rows.map(b => ({ ...b, bonus: rows.length ? b.perEuro / rows[0].perEuro - 1 : 0 }));
  });

  /** Lukaten je Euro: bestes und schlechtestes Bündel */
  bestRate = computed(() => Math.max(0, ...this.bundles().map(b => b.perEuro)));
  worstRate = computed(() => Math.min(...this.bundles().map(b => b.perEuro)));

  // ── Euro → Sticker: Angebote des Shops, gegen "dasselbe Geld als Lukaten-Bündel" ──
  offers = computed(() => {
    const price = this.price();
    const bundles = this.bundles();
    const best = this.bestRate();
    if (!bundles.length) return [];
    return [...EUR_OFFERS, EUR_STARTER].map(o => {
      const value = offerKinds(o).reduce((v, x) => v + x.count * price[x.kind], 0);
      // gleicher Preis wie ein Bündel → genau vergleichbar; sonst zum besten Kurs gerechnet
      const same = bundles.find(b => Math.abs(b.eur - o.price) < 0.005);
      const sameMoney = same ? same.lukaten : o.price * best;
      return {
        key: o.key, name: o.name, once: !!o.once, eur: o.price,
        contents: contentsLabel(o), stickers: stickerCount(o),
        value,                              // Wert der enthaltenen Packs in Lukaten
        sameMoney,                          // Lukaten, die es fürs gleiche Geld als Bündel gäbe
        exact: !!same,
        valuePerEuro: value / o.price,
        lukatenPerEuro: sameMoney / o.price,
        bonus: value / sameMoney - 1,       // so viel mehr bekommt man direkt
      };
    });
  });

  /** ohne das einmalige Starter-Angebot — das ist bewusst ein Ausreißer */
  regular = computed(() => this.offers().filter(o => !o.once));

  private barMax = computed(() => Math.max(1, ...this.regular().map(o => Math.max(o.valuePerEuro, o.lukatenPerEuro))));
  /** Balkenlänge in Prozent (Lukaten-Wert je Euro, gemessen am besten regulären Angebot; Ausreißer gedeckelt) */
  bar(perEuro: number): string {
    return Math.min(100, (perEuro / this.barMax()) * 100) + '%';
  }

  // ── Pack-Arten: was kostet ein Pack je Weg in Euro ──
  kindRoutes = computed(() => {
    const best = this.bestRate();
    const worst = this.worstRate();
    return this.kinds().map(k => {
      // reine Angebote dieser Pack-Art (ohne das einmalige): Preis je Pack
      const direct = EUR_OFFERS
        .filter(o => { const c = offerKinds(o); return c.length === 1 && c[0].kind === k.kind; })
        .map(o => o.price / offerKinds(o)[0].count);
      const directEur = direct.length ? Math.min(...direct) : null;
      const viaMin = best > 0 ? k.price / best : 0;
      const viaMax = worst > 0 ? k.price / worst : 0;
      return { ...k, directEur, viaMin, viaMax, bonus: directEur ? viaMin / directEur - 1 : null };
    });
  });

  // ── Das Dreieck in Zahlen ──
  packRange = computed(() => this.range(this.kinds().map(k => k.price)));
  rateRange = computed(() => this.range(this.bundles().map(b => b.perEuro)));
  directRange = computed(() => this.range(this.regular().map(o => o.valuePerEuro)));
  bonusRange = computed(() => this.range(this.regular().map(o => o.bonus)));

  private range(values: number[]): { min: number; max: number } {
    return values.length ? { min: Math.min(...values), max: Math.max(...values) } : { min: 0, max: 0 };
  }

  /** Ein ganzer Spieltag Einträge in normalen Packs und Stickern */
  matchday = computed(() => {
    const normal = this.kinds().find(k => k.kind === 'normal');
    if (!normal || normal.price <= 0) return null;
    const packs = Math.floor(ENTRIES_PER_MATCHDAY / normal.price);
    return { packs, stickers: packs * normal.size };
  });

  // ── Auffälligkeiten ──
  findings = computed<Finding[]>(() => {
    const out: Finding[] = [];
    const offers = this.regular();
    if (!offers.length) return out;

    for (const o of offers) {
      if (o.bonus <= 0) {
        out.push({ level: 'error', text: `${o.name}: der Umweg über gekaufte Lukaten ist gleich gut oder günstiger (${this.pct(o.bonus)}).` });
      } else if (o.bonus < LOW_BONUS) {
        out.push({ level: 'warn', text: `${o.name}: direkt lohnt sich nur knapp (${this.pct(o.bonus)}).` });
      }
    }

    // Staffel der Euro-Kombinationen: wer mehr ausgibt, sollte je Euro nicht weniger bekommen
    const combos = offers.filter(o => !EUR_OFFERS.find(e => e.key === o.key && offerKinds(e).length === 1 && offerKinds(e)[0].count === 1))
      .sort((a, b) => a.eur - b.eur);
    for (let i = 1; i < combos.length; i++) {
      if (combos[i].valuePerEuro < combos[i - 1].valuePerEuro - 0.5) {
        out.push({ level: 'warn', text: `${combos[i].name} bringt je Euro weniger als ${combos[i - 1].name} (${this.n(combos[i].valuePerEuro)} gegen ${this.n(combos[i - 1].valuePerEuro)} Lukaten Wert).` });
      }
    }

    // Staffel der Lukaten-Bündel
    const bundles = this.bundles();
    for (let i = 1; i < bundles.length; i++) {
      if (bundles[i].perEuro < bundles[i - 1].perEuro - 0.05) {
        out.push({ level: 'warn', text: `Das Bündel für ${this.eur(bundles[i].eur)} bringt je Euro weniger Lukaten als das für ${this.eur(bundles[i - 1].eur)}.` });
      }
    }

    // Big Pack sollte je Sticker nicht teurer sein als das normale
    const normal = this.kinds().find(k => k.kind === 'normal');
    const big = this.kinds().find(k => k.kind === 'big');
    if (normal && big && big.perSticker > normal.perSticker + 0.01) {
      out.push({ level: 'warn', text: `Das Big Pack ist je Sticker teurer als das normale (${this.n(big.perSticker, 1)} gegen ${this.n(normal.perSticker, 1)} Lukaten).` });
    }

    // einzelne Packs gegen Euro: direkt sollte günstiger sein als über Lukaten
    for (const k of this.kindRoutes()) {
      if (k.directEur !== null && k.bonus !== null && k.bonus <= 0) {
        out.push({ level: 'error', text: `${k.name}: über gekaufte Lukaten (ab ${this.eur(k.viaMin)}) nicht teurer als direkt (${this.eur(k.directEur)}).` });
      }
    }

    // Der Bonus sollte nicht zu ungleich über die Angebote verteilt sein
    const b = this.bonusRange();
    if (b.max - b.min > 0.6) {
      out.push({ level: 'warn', text: `Der Vorteil des direkten Kaufs schwankt stark zwischen den Angeboten (${this.pct(b.min)} bis ${this.pct(b.max)}).` });
    }

    if (!out.length) {
      out.push({ level: 'ok', text: `Stimmig: direkt gegen Euro ist bei jedem Angebot besser als der Umweg (${this.pct(b.min)} bis ${this.pct(b.max)}), und alle Staffeln steigen.` });
    }
    return out;
  });

  // ── Anzeige ──
  n(v: number, digits = 0): string {
    return v.toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  }

  eur(v: number): string {
    return v.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
  }

  /** z.B. "+67 %" */
  pct(v: number): string {
    const p = Math.round(v * 100);
    return (p > 0 ? '+' : p < 0 ? '−' : '±') + Math.abs(p) + ' %';
  }

  num(event: Event): number {
    return +(event.target as HTMLInputElement).value;
  }
}
