/** Die 7 gültigen Formationen als [TOR, ABW, MIT, STU] — wie team_lineup.database.php (VALID_FORMATIONS) */
export const VALID_FORMATIONS: number[][] = [
  [1, 3, 4, 3], [1, 3, 5, 2], [1, 4, 3, 3], [1, 4, 4, 2], [1, 4, 5, 1], [1, 5, 3, 2], [1, 5, 4, 1],
];

/** Klassische Form, auf die eine leere/unvollständige Aufstellung ergänzt wird (nur Tiebreaker) */
export const FALLBACK_FORMATION = [1, 4, 4, 2];

export const POSITION_INDEX: Record<string, number> = { GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3 };

/**
 * Formation, auf die eine (teilweise) Aufstellung hinausläuft — daraus ergeben sich die leeren Plätze. Bei 10 oder
 * weniger Spielern ist nicht eindeutig, wo jemand fehlt; meist wurde ein aufgestellter Spieler verkauft und die
 * Aufstellung danach nicht angepasst. Deshalb zuerst die Formation vom vorherigen Spieltag (`previous`, GET
 * /team_lineup → previous_formation), sofern sie gültig und von den aufgestellten Spielern aus noch erreichbar ist;
 * sonst unter den noch erreichbaren gültigen Formationen die nächste an 4-4-2. Ist keine mehr erreichbar, bleibt es
 * bei der aktuellen Verteilung (keine leeren Plätze).
 */
export function completionFormation(counts: number[], previous?: number[] | null): number[] {
  const reachable = (f: number[]) => f.every((v, i) => v >= counts[i]);
  if (previous && reachable(previous) && VALID_FORMATIONS.some(f => f.every((v, i) => v === previous[i]))) {
    return previous;
  }
  const compatible = VALID_FORMATIONS.filter(reachable);
  if (compatible.length === 0) return counts;
  const distance = (f: number[]) => f.reduce((sum, v, i) => sum + Math.abs(v - FALLBACK_FORMATION[i]), 0);
  return compatible.reduce((best, f) => (distance(f) < distance(best) ? f : best));
}

/** Aufgestellte Spieler je Position als [TOR, ABW, MIT, STU] */
export function positionCounts(players: { position?: string | null }[]): number[] {
  const counts = [0, 0, 0, 0];
  for (const p of players) {
    const i = POSITION_INDEX[p.position ?? ''];
    if (i !== undefined) counts[i]++;
  }
  return counts;
}

/**
 * Hinweis über einer unvollständigen Aufstellung (weniger als 11 aufgestellt) — sie ist ungültig und bringt 0 Punkte,
 * live wie beim Abschluss (finalizeMatchday()).
 */
export function incompleteLineupLabel(nominated: number): string {
  return nominated === 0 ? 'Keine Aufstellung vorhanden' : `Unvollständige Aufstellung · ${nominated} von 11`;
}

/** Zeile unter dem Hinweis, wenn nur verkaufte Spieler fehlen: "Müller verkauft" bzw. "Müller, Kane verkauft" */
export function soldPlayersLabel(names: string[]): string {
  return `${names.join(', ')} verkauft`;
}
