// "Die Klebrigsten" V0 — reine Simulationslogik (ohne Angular), deterministisch per Seed.

export interface SimParams {
  dailyPackSize: number;        // Sticker pro täglichem Login-Pack (0 = kein Daily)
  loginChance: number;          // 0–1, Wahrscheinlichkeit, sich an einem Tag einzuloggen
  guaranteeNew: boolean;        // 1. Sticker jedes Packs garantiert neu (solange welche fehlen)
  milestoneInterval: number;    // Saisonpunkte-Abstand zwischen Meilenstein-Packs
  milestonePackSize: number;    // Sticker pro Meilenstein-Pack (0 = aus)
  avgPoints: number;            // Ø Teampunkte pro Spieltag
  bestPackSize: number;         // Sticker pro Spieltagsbester-Pack (0 = aus)
  bestChance: number;           // 0–1, Chance, an einem Spieltag Spieltagsbester zu sein (profilabhängig)
  rarityAlpha: number;          // Gewicht je Sticker = Marktwert^-α (0 = alle gleich häufig)
}

/** Manager-Typ: die Werte, in denen sich aktive und inaktive Manager unterscheiden. */
export interface SimProfile {
  key: string;
  label: string;
  loginChance: number;
  avgPoints: number;
  bestChance: number;
}

export type PackSource = 'daily' | 'milestone' | 'best';

export interface SimPack {
  day: number;
  source: PackSource;
  stickers: number[];           // Indizes ins flache Album
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

/** Simuliert eine komplette Saison und liefert alle Packs chronologisch. */
export function simulateSeason(params: SimParams, weights: number[], timeline: Timeline, seed: number): SimPack[] {
  const random = rng(seed);
  const n = weights.length;
  const cum: number[] = [];
  let acc = 0;
  for (const w of weights) { acc += w; cum.push(acc); }

  const owned = new Uint8Array(n);
  let missing = n;

  const drawAny = (): number => {
    const r = random() * acc;
    let lo = 0, hi = n - 1;
    while (lo < hi) { const mid = (lo + hi) >> 1; if (cum[mid] < r) lo = mid + 1; else hi = mid; }
    return lo;
  };
  const drawMissing = (): number => {
    let total = 0;
    for (let i = 0; i < n; i++) if (!owned[i]) total += weights[i];
    let r = random() * total;
    for (let i = 0; i < n; i++) if (!owned[i]) { r -= weights[i]; if (r <= 0) return i; }
    return drawAny();
  };
  const take = (i: number) => { if (!owned[i]) { owned[i] = 1; missing--; } return i; };

  const packs: SimPack[] = [];
  const open = (day: number, source: PackSource, size: number) => {
    if (size <= 0 || n === 0) return;
    const stickers: number[] = [];
    for (let k = 0; k < size; k++) {
      stickers.push(take(params.guaranteeNew && k === 0 && missing > 0 ? drawMissing() : drawAny()));
    }
    packs.push({ day: Math.max(day, 0), source, stickers });
  };

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
          open(day, 'milestone', params.milestonePackSize);
        }
      }
      if (random() < params.bestChance) open(day, 'best', params.bestPackSize);
    }
  }
  return packs;
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
