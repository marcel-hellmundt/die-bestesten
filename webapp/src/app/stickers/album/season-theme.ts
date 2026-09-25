// "Die Klebrigsten" — Saison-Theme des Sammelalbums: deterministisch aus der Saison-ID abgeleitet
// (jede Saison sieht anders aus, bleibt aber stabil — ohne DB). Liefert ein Hintergrundmuster als
// reine CSS-Gradients; die Farben kommen je Seite aus dem Verein (CSS-Variablen --page-a / --page-b).
import { rng } from '../sticker-sim';
import { hashSeed } from './sticker-album.service';

export type ThemePattern = 'stripes' | 'dots' | 'chevron' | 'waves' | 'grid';
export type LogoCorner = 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right';

export interface SeasonTheme {
  pattern: ThemePattern;
  angle: number;         // Grad (Streifen)
  scale: number;         // Kachelgröße in px
  logoCorner: LogoCorner;
  logoRotation: number;  // Grad, großes Wasserzeichen-Wappen
  sweepAngle: number;    // Richtung des Farbverlaufs der Seite
}

const PATTERNS: ThemePattern[] = ['stripes', 'dots', 'chevron', 'waves', 'grid'];
const CORNERS: LogoCorner[] = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];

export function seasonTheme(seasonId: string | null): SeasonTheme {
  const r = rng(hashSeed(`album-theme:${seasonId ?? 'none'}`));
  const pick = <T>(list: T[]) => list[Math.floor(r() * list.length)];
  return {
    pattern: pick(PATTERNS),
    angle: Math.round(20 + r() * 140),
    scale: Math.round(18 + r() * 22),
    logoCorner: pick(CORNERS),
    logoRotation: Math.round(-25 + r() * 50),
    sweepAngle: Math.round(100 + r() * 70),
  };
}

/** Muster als background-image/-size (Farbe über --page-b, dezent per color-mix). */
export function themePattern(t: SeasonTheme): { image: string; size: string } {
  const c = 'color-mix(in srgb, var(--page-b) 22%, transparent)';
  const s = t.scale;
  switch (t.pattern) {
    case 'stripes':
      return {
        image: `repeating-linear-gradient(${t.angle}deg, ${c} 0 ${Math.round(s * 0.35)}px, transparent ${Math.round(s * 0.35)}px ${s}px)`,
        size: 'auto',
      };
    case 'dots':
      return { image: `radial-gradient(circle, ${c} 22%, transparent 24%)`, size: `${s}px ${s}px` };
    case 'chevron':
      return {
        image: `linear-gradient(135deg, ${c} 25%, transparent 25%), linear-gradient(225deg, ${c} 25%, transparent 25%)`,
        size: `${s}px ${s}px`,
      };
    case 'waves':
      return {
        image: `repeating-radial-gradient(circle at 0 100%, transparent 0 ${Math.round(s * 0.35)}px, ${c} ${Math.round(s * 0.35)}px ${Math.round(s * 0.5)}px, transparent ${Math.round(s * 0.5)}px ${s}px)`,
        size: `${s * 2}px ${s * 2}px`,
      };
    case 'grid':
      return {
        image: `linear-gradient(${c} 1px, transparent 1px), linear-gradient(90deg, ${c} 1px, transparent 1px)`,
        size: `${s}px ${s}px`,
      };
  }
}
