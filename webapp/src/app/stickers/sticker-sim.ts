// "Die Klebrigsten" V0 — reine Simulationslogik (ohne Angular), deterministisch per Seed.
import { EUR_OFFERS, EUR_STARTER, LUKATEN_OFFERS, ShopOffer, stickerCount } from './shop/shop.model';

/** Wann über die Saison im Shop gekauft wird. */
export type ShopTiming = 'start' | 'spread' | 'late';

export interface SimParams {
  dailyPackSize: number;        // Sticker pro täglichem Login-Pack (0 = kein Daily)
  loginChance: number;          // 0–1, Wahrscheinlichkeit, sich an einem Tag einzuloggen
  guaranteeNew: boolean;        // 1. Sticker jedes Packs garantiert neu (solange welche fehlen)
  milestoneInterval: number;    // Saisonpunkte-Abstand zwischen Meilenstein-Packs
  milestonePackSize: number;    // Sticker pro Meilenstein-Pack (0 = aus)
  milestoneAllNew: boolean;     // alle Sticker eines Meilenstein-Packs garantiert neu (solange welche fehlen)
  avgPoints: number;            // Ø Teampunkte pro Spieltag
  bestPackSize: number;         // Sticker pro Spieltagsbester-Pack (0 = aus)
  bestAllNew: boolean;          // alle Sticker eines Spieltagsbester-Packs garantiert neu
  bestChance: number;           // 0–1, Chance, an einem Spieltag Spieltagsbester zu sein (profilabhängig)
  rarityAlpha: number;          // Gewicht je Sticker = Marktwert^-α (0 = alle gleich häufig)
  holoSilverChance: number;     // 0–1, Chance je gezogenem Sticker, dass er eine Holo-Silber-Karte ist
  holoGoldChance: number;       // 0–1, Chance je gezogenem Sticker, dass er eine Holo-Gold-Karte ist
  shopLukaten: number;          // Lukaten, die pro Saison im Shop gegen Packs getauscht werden (0 = keine)
  shopEuro: number;             // Euro, die pro Saison für Packs ausgegeben werden (0 = keine)
  shopTiming: ShopTiming;       // Kaufzeitpunkt: alles zum Start, über die Saison verteilt oder in der Rückrunde
}

/** Variante eines gezogenen Stickers: null = normal. */
export type HoloVariant = 'silver' | 'gold' | null;

/** Manager-Typ: die Werte, in denen sich aktive und inaktive Manager unterscheiden. */
export interface SimProfile {
  key: string;
  label: string;
  loginChance: number;
  avgPoints: number;
  bestChance: number;
}

export type PackSource = 'daily' | 'milestone' | 'best' | 'shop';

export interface SimPack {
  day: number;
  source: PackSource;
  stickers: number[];           // Indizes ins flache Album
  holo: HoloVariant[];          // je Sticker (gleicher Index): Holo-Variante oder null
}

export interface Timeline {
  days: number;                 // Tage 0..days (Tag 0 = Stichtag)
  matchdayDays: number[];       // Auswertungstag je Spieltag (kann < 0 sein → vor Albumstart)
}

export const MIN_PRICE = 500_000;

/** Mulberry32 — kleiner, schneller Seed-PRNG (0 ≤ x < 1). */
export function rng(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Normierte Ziehgewichte je Sticker (Summe 1). */
export function stickerWeights(prices: (number | null)[], alpha: number): number[] {
  const w = prices.map(p => Math.pow(Math.max(p ?? MIN_PRICE, MIN_PRICE), -alpha));
  const total = w.reduce((a, b) => a + b, 0);
  return w.map(x => x / total);
}

// ── Shop ─────────────────────────────────────────────────────────────────────

export interface ShopPlan {
  offers: ShopOffer[];   // gekaufte Angebote (je Eintrag ein Kauf)
  packs: number;
  stickers: number;
  spentLukaten: number;
  spentEuro: number;
}

/** Unbeschränkter Rucksack: meiste Sticker für höchstens `budget` Einheiten (Lukaten bzw. Cent). */
function knapsack(offers: ShopOffer[], budget: number, unit: number): { value: number; items: ShopOffer[] } {
  const cost = offers.map(o => Math.round(o.price * unit));
  const dp = new Float64Array(budget + 1);
  const choice = new Int16Array(budget + 1).fill(-1); // -1 = wie budget-1 (Rest bleibt übrig)
  for (let b = 1; b <= budget; b++) {
    dp[b] = dp[b - 1];
    offers.forEach((o, i) => {
      if (cost[i] <= b && dp[b - cost[i]] + stickerCount(o) > dp[b]) { dp[b] = dp[b - cost[i]] + stickerCount(o); choice[b] = i; }
    });
  }
  const items: ShopOffer[] = [];
  for (let b = budget; b > 0;) {
    if (choice[b] < 0) { b--; continue; }
    items.push(offers[choice[b]]);
    b -= cost[choice[b]];
  }
  return { value: dp[budget], items };
}

/** Meiste Sticker fürs Budget; Einmal-Angebote (Starter) höchstens einmal, Rest per Rucksack. */
function bestBundle(offers: ShopOffer[], budget: number, unit: number): ShopOffer[] {
  const B = Math.floor(budget * unit + 1e-6);
  const once = offers.filter(o => o.once), multi = offers.filter(o => !o.once);
  let best = { value: -1, items: [] as ShopOffer[] };
  for (let mask = 0; mask < 1 << once.length; mask++) {
    const chosen = once.filter((_, i) => mask & (1 << i));
    const c = chosen.reduce((a, o) => a + Math.round(o.price * unit), 0);
    if (c > B) continue;
    const rest = knapsack(multi, B - c, unit);
    const value = chosen.reduce((a, o) => a + stickerCount(o), 0) + rest.value;
    if (value > best.value) best = { value, items: [...chosen, ...rest.items] };
  }
  return best.items;
}

/**
 * Was ein Manager mit `lukaten` + `euro` pro Saison kauft: je Währung die meisten Sticker (ohne
 * Vereins-Packs), was übrig bleibt, geht in Vereins-Packs — gezielt für den Verein, dem am wenigsten fehlt.
 */
export function shopPlan(lukaten: number, euro: number): ShopPlan {
  const plan = (offers: ShopOffer[], budget: number, unit: number) => {
    const bundle = bestBundle(offers.filter(o => !o.clubPick), budget, unit);
    const club = offers.find(o => o.clubPick);
    let left = budget - bundle.reduce((a, o) => a + o.price, 0);
    if (club) while (left + 1e-6 >= club.price) { bundle.push(club); left -= club.price; }
    return bundle;
  };
  const l = lukaten > 0 ? plan(LUKATEN_OFFERS, lukaten, 1) : [];
  const e = euro > 0 ? plan([EUR_STARTER, ...EUR_OFFERS], euro, 100) : [];
  const offers = [...l, ...e];
  return {
    offers,
    packs: offers.reduce((a, o) => a + o.packs, 0),
    stickers: offers.reduce((a, o) => a + stickerCount(o), 0),
    spentLukaten: l.reduce((a, o) => a + o.price, 0),
    spentEuro: Math.round(e.reduce((a, o) => a + o.price, 0) * 100) / 100,
  };
}

/** Kauftag je Angebot: alles am Start, gleichmäßig über die Saison oder gleichmäßig über die Rückrunde. */
function shopDays(count: number, days: number, timing: ShopTiming): number[] {
  if (timing === 'start') return Array(count).fill(0);
  const from = timing === 'late' ? Math.round(days / 2) : 0;
  return Array.from({ length: count }, (_, k) => Math.round(from + (k + 0.5) / count * (days - from)));
}

/**
 * Simuliert eine komplette Saison und liefert alle Packs chronologisch.
 * `clubOf` (Vereins-Index je Sticker) wird nur für Vereins-Packs aus dem Shop gebraucht.
 */
export function simulateSeason(params: SimParams, weights: number[], timeline: Timeline, seed: number, clubOf?: ArrayLike<number>): SimPack[] {
  const random = rng(seed);
  // Shop-Züge mit eigenem Zufallsstrom — Logins/Punkte bleiben so gleich, egal wie viel im Shop gekauft wird
  const shopRandom = rng(seed ^ 0x51ed27);
  const n = weights.length;
  const cum: number[] = [];
  let acc = 0;
  for (const w of weights) { acc += w; cum.push(acc); }

  const owned = new Uint8Array(n);
  let missing = n;

  // Vereins-Zuordnung für Vereins-Packs
  const clubCount = clubOf ? Math.max(-1, ...Array.from(clubOf)) + 1 : 0;
  const clubMissing = new Int32Array(clubCount);
  if (clubOf) for (let i = 0; i < n; i++) clubMissing[clubOf[i]]++;

  const drawAny = (rand = random): number => {
    const r = rand() * acc;
    let lo = 0, hi = n - 1;
    while (lo < hi) { const mid = (lo + hi) >> 1; if (cum[mid] < r) lo = mid + 1; else hi = mid; }
    return lo;
  };
  /** Gewichteter Zug nur unter Stickern, die `ok` erfüllen (null = keiner). */
  const drawWhere = (ok: (i: number) => boolean, rand: () => number): number | null => {
    let total = 0;
    for (let i = 0; i < n; i++) if (ok(i)) total += weights[i];
    if (total <= 0) return null;
    let r = rand() * total;
    for (let i = 0; i < n; i++) if (ok(i)) { r -= weights[i]; if (r <= 0) return i; }
    return null;
  };
  const drawMissing = (rand = random): number => drawWhere(i => !owned[i], rand) ?? drawAny(rand);
  const take = (i: number) => {
    if (!owned[i]) { owned[i] = 1; missing--; if (clubOf) clubMissing[clubOf[i]]--; }
    return i;
  };

  // Holo-Wurf mit eigenem Zufallsstrom: gleiche Seed → identische Sticker-Züge, auch wenn nur die
  // Holo-Chancen verstellt werden (sonst würde jede Holo-Änderung die komplette Saison umwürfeln)
  const holoRandom = rng(seed ^ 0x9e3779b9);
  const rollHolo = (): HoloVariant => {
    const r = holoRandom();
    if (r < params.holoGoldChance) return 'gold';
    if (r < params.holoGoldChance + params.holoSilverChance) return 'silver';
    return null;
  };

  const packs: SimPack[] = [];
  const open = (day: number, source: PackSource, size: number, allNew = false) => {
    if (size <= 0 || n === 0) return;
    const stickers: number[] = [];
    const holo: HoloVariant[] = [];
    for (let k = 0; k < size; k++) {
      const guaranteed = allNew || (params.guaranteeNew && k === 0);
      stickers.push(take(guaranteed && missing > 0 ? drawMissing() : drawAny()));
      holo.push(rollHolo());
    }
    packs.push({ day: Math.max(day, 0), source, stickers, holo });
  };

  /** Shop-Pack: `guaranteedNew` Karten garantiert neu; Vereins-Pack nur aus dem Verein mit den wenigsten fehlenden. */
  const openShop = (day: number, o: ShopOffer) => {
    if (n === 0) return;
    let club = -1;
    if (o.clubPick && clubOf) {
      for (let c = 0; c < clubCount; c++) {
        if (clubMissing[c] > 0 && (club < 0 || clubMissing[c] < clubMissing[club])) club = c;
      }
    }
    const inClub = (i: number) => club < 0 || clubOf![i] === club;
    for (let p = 0; p < o.packs; p++) {
      const stickers: number[] = [];
      const holo: HoloVariant[] = [];
      for (let k = 0; k < o.packSize; k++) {
        const pick = k < o.guaranteedNew ? drawWhere(i => !owned[i] && inClub(i), shopRandom) : null;
        stickers.push(take(pick ?? drawWhere(inClub, shopRandom) ?? drawAny(shopRandom)));
        holo.push(rollHolo());
      }
      packs.push({ day, source: 'shop', stickers, holo });
    }
  };
  const plan = shopPlan(params.shopLukaten, params.shopEuro).offers;
  const buyDays = shopDays(plan.length, timeline.days, params.shopTiming);
  const shopByDay = new Map<number, ShopOffer[]>();
  plan.forEach((o, k) => shopByDay.set(buyDays[k], [...(shopByDay.get(buyDays[k]) ?? []), o]));

  // Teampunkte je Spieltag: Normalverteilung um avgPoints (±35 %), nie negativ
  const gauss = () => {
    const u = Math.max(random(), 1e-9), v = random();
    return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
  };
  const mdByDay = new Map<number, number>(); // Tag → Anzahl Spieltage, die an diesem Tag ausgewertet werden
  for (const d of timeline.matchdayDays) {
    const day = Math.max(d, 0);
    mdByDay.set(day, (mdByDay.get(day) ?? 0) + 1);
  }

  let cumPoints = 0;
  let milestonesGiven = 0;
  for (let day = 0; day <= timeline.days; day++) {
    if (params.dailyPackSize > 0 && random() < params.loginChance) open(day, 'daily', params.dailyPackSize);
    for (let m = 0; m < (mdByDay.get(day) ?? 0); m++) {
      cumPoints += Math.max(0, params.avgPoints + gauss() * params.avgPoints * 0.35);
      if (params.milestoneInterval > 0) {
        while ((milestonesGiven + 1) * params.milestoneInterval <= cumPoints) {
          milestonesGiven++;
          open(day, 'milestone', params.milestonePackSize, params.milestoneAllNew);
        }
      }
      if (random() < params.bestChance) open(day, 'best', params.bestPackSize, params.bestAllNew);
    }
    for (const o of shopByDay.get(day) ?? []) openShop(day, o);
  }
  return packs;
}

/** Holo-Karten je Sticker (Silber/Gold getrennt) nach allen Packs bis einschließlich `day`. */
export function holoAtDay(packs: SimPack[], n: number, day: number): { silver: Uint16Array; gold: Uint16Array } {
  const silver = new Uint16Array(n), gold = new Uint16Array(n);
  for (const p of packs) {
    if (p.day > day) break;
    p.stickers.forEach((s, k) => {
      if (p.holo[k] === 'silver') silver[s]++;
      else if (p.holo[k] === 'gold') gold[s]++;
    });
  }
  return { silver, gold };
}

/** Zählerstand je Sticker nach allen Packs bis einschließlich `day`. */
export function countsAtDay(packs: SimPack[], n: number, day: number): Uint16Array {
  const counts = new Uint16Array(n);
  for (const p of packs) {
    if (p.day > day) break;
    for (const s of p.stickers) counts[s]++;
  }
  return counts;
}
