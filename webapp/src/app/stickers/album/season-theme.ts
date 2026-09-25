// "Die Klebrigsten" — Saison-Theme des Sammelalbums: deterministisch aus der Saison-ID abgeleitet
// (jede Saison sieht anders aus, bleibt aber stabil — ohne DB). Bewusst ruhig: statt eines flächigen
// Musters eine einzelne breite "Trikot-Schärpe" quer über die Seite plus ein feines Punkt-Raster
// (Druck-Optik), das in einer Ecke ausläuft. Die Farben kommen je Seite aus dem Verein
// (CSS-Variablen --page-a / --sash, siehe album-club-page).
import { rng } from '../sticker-sim';
import { hashSeed } from './sticker-album.service';

export type Corner = 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right';

export interface SeasonTheme {
  sashAngle: number;     // Grad — Richtung der Schärpe
  sashOffset: number;    // % — wo die Schärpe die Seite kreuzt
  sashWidth: number;     // % der Seitendiagonale
  logoCorner: Corner;    // großes Wasserzeichen-Wappen
  logoRotation: number;  // Grad
  dotsCorner: Corner;    // Punkt-Raster läuft von dieser Ecke aus (immer gegenüber dem Wappen)
  sweepAngle: number;    // Richtung des Farbverlaufs der Seite
}

const CORNERS: Corner[] = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
const OPPOSITE: Record<Corner, Corner> = {
  'top-left': 'bottom-right', 'top-right': 'bottom-left', 'bottom-left': 'top-right', 'bottom-right': 'top-left',
};

export function seasonTheme(seasonId: string | null): SeasonTheme {
  const r = rng(hashSeed(`album-theme:${seasonId ?? 'none'}`));
  const logoCorner = CORNERS[Math.floor(r() * CORNERS.length)];
  // Schärpe diagonal, aber nie parallel zu einer Kante
  const sashAngle = Math.round(r() < 0.5 ? 115 + r() * 30 : 35 + r() * 30);
  return {
    sashAngle,
    sashOffset: Math.round(34 + r() * 22),
    sashWidth: Math.round(14 + r() * 8),
    logoCorner,
    logoRotation: Math.round(-25 + r() * 50),
    dotsCorner: OPPOSITE[logoCorner],
    sweepAngle: Math.round(100 + r() * 70),
  };
}

/** Schärpe als background-image: breites, weich begrenztes Band (--sash) mit feiner Begleitlinie (--page-a). */
export function themeSash(t: SeasonTheme): string {
  const band = 'color-mix(in srgb, var(--sash) 13%, transparent)';
  const line = 'color-mix(in srgb, var(--page-a) 28%, transparent)';
  const a = t.sashOffset, b = a + t.sashWidth;
  return `linear-gradient(${t.sashAngle}deg,
    transparent ${a}%, ${band} ${a + 0.6}%, ${band} ${b}%, transparent ${b + 0.6}%,
    transparent ${b + 1.6}%, ${line} ${b + 1.8}%, ${line} ${b + 2.3}%, transparent ${b + 2.5}%)`;
}

/** Position (für mask/radial-gradient) der Ecke, aus der das Punkt-Raster ausläuft. */
export function cornerPosition(c: Corner): string {
  const pos: Record<Corner, string> = {
    'top-left': '0% 0%', 'top-right': '100% 0%', 'bottom-left': '0% 100%', 'bottom-right': '100% 100%',
  };
  return pos[c];
}
