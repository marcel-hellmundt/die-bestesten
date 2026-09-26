// "Die Klebrigsten" — Liga-Simulation mit Tauschen unter Managern (ohne Angular), deterministisch per Seed.
//
// Jeder Manager zieht seine Packs wie in simulateSeason(); in regelmäßigen Tauschrunden tauschen alle, die
// an dem Tag eingeloggt sind, paarweise 1:1 Doppelte gegeneinander, die dem jeweils anderen fehlen —
// seltenste zuerst, wahlweise nur innerhalb derselben Seltenheitsstufe (niemand gibt einen legendären
// Doppelten für einen häufigen her). Näherung: die Neu-Garantie der Packs kennt getauschte Sticker nicht
// (ein garantiert neuer Sticker kann also einer sein, den man inzwischen per Tausch hat) → eher pessimistisch.
import { SimParams, Timeline, rng, simulateSeason } from './sticker-sim';

export interface LeagueMember {
  profileKey: string;
  params: SimParams;
}

export interface TradeOptions {
  interval: number;    // Tage zwischen zwei Tauschrunden (0 = kein Tauschen)
  sameTier: boolean;   // nur gleiche Seltenheit gegeneinander tauschen
}

export interface MemberResult {
  profileKey: string;
  uniqueNoTrade: number;   // verschiedene Sticker am Saisonende ohne Tauschen
  unique: number;          // … mit Tauschen
  duplicates: number;      // Doppelte am Saisonende (mit Tauschen)
  received: number;        // per Tausch erhaltene Sticker
}

export function simulateLeague(
  members: LeagueMember[], weights: number[], tiers: string[], timeline: Timeline, seed: number,
  clubOf: ArrayLike<number>, opts: TradeOptions,
): MemberResult[] {
  const n = weights.length;
  const random = rng(seed ^ 0x7a3c11);
  const packs = members.map((m, k) => simulateSeason(m.params, weights, timeline, seed + k * 104_729, clubOf));
  const counts = members.map(() => new Uint16Array(n));
  const received = members.map(() => 0);
  const next = members.map(() => 0);

  // Tauschreihenfolge: seltenste Sticker zuerst
  const byRarity = Array.from({ length: n }, (_, i) => i).sort((a, b) => weights[a] - weights[b]);

  const tradePair = (a: number, b: number) => {
    const ca = counts[a], cb = counts[b];
    // Was a doppelt hat und b fehlt (und umgekehrt), je Stufe (bzw. alles in einer Gruppe)
    const give = new Map<string, number[]>(), take = new Map<string, number[]>();
    for (const s of byRarity) {
      const g = opts.sameTier ? tiers[s] : '';
      if (ca[s] >= 2 && cb[s] === 0) (give.get(g) ?? give.set(g, []).get(g)!).push(s);
      if (cb[s] >= 2 && ca[s] === 0) (take.get(g) ?? take.set(g, []).get(g)!).push(s);
    }
    for (const [g, gs] of give) {
      const ts = take.get(g) ?? [];
      for (let k = 0; k < Math.min(gs.length, ts.length); k++) {
        ca[gs[k]]--; cb[gs[k]]++;
        cb[ts[k]]--; ca[ts[k]]++;
        received[a]++; received[b]++;
      }
    }
  };

  for (let day = 0; day <= timeline.days; day++) {
    members.forEach((_, k) => {
      const list = packs[k];
      while (next[k] < list.length && list[next[k]].day <= day) {
        for (const s of list[next[k]].stickers) counts[k][s]++;
        next[k]++;
      }
    });
    if (opts.interval > 0 && day > 0 && day % opts.interval === 0) {
      // wer heute eingeloggt ist, tauscht mit — in zufälliger Reihenfolge
      const present = members.map((m, k) => (random() < m.params.loginChance ? k : -1)).filter(k => k >= 0);
      for (let i = present.length - 1; i > 0; i--) {
        const j = Math.floor(random() * (i + 1));
        [present[i], present[j]] = [present[j], present[i]];
      }
      for (let i = 0; i < present.length; i++) {
        for (let j = i + 1; j < present.length; j++) tradePair(present[i], present[j]);
      }
    }
  }

  return members.map((m, k) => {
    let unique = 0, total = 0, uniqueNoTrade = 0;
    const own = new Uint8Array(n);
    for (const p of packs[k]) for (const s of p.stickers) own[s] = 1;
    for (let s = 0; s < n; s++) { total += counts[k][s]; if (counts[k][s]) unique++; if (own[s]) uniqueNoTrade++; }
    return { profileKey: m.profileKey, uniqueNoTrade, unique, duplicates: total - unique, received: received[k] };
  });
}
