import { Component, computed, inject, input, output } from '@angular/core';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { AlbumClub, POSITION_ORDER, Sticker, initials } from './album.model';
import { Collection, StickerAlbumService, hashSeed } from './sticker-album.service';
import { SeasonTheme, cornerPosition, themeSash } from './season-theme';

export interface AlbumSlot {
  sticker: Sticker;
  count: number;
  card: StickerCardData | null;   // null = noch nicht gesammelt (leerer Slot)
  initials: string;
  tilt: number;                   // Grad — leichte, stabile Schräglage wie eingeklebt (nur Desktop)
}

/** Relative Helligkeit eines Hex-Werts (#rrggbb) > 0.8 → gilt als "fast weiß". */
function isLight(hex: string): boolean {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex);
  if (!m) return false;
  const n = parseInt(m[1], 16);
  const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255 > 0.8;
}

const SECTION_LABEL: Record<string, string> = {
  GOALKEEPER: 'Tor', DEFENDER: 'Abwehr', MIDFIELDER: 'Mittelfeld', FORWARD: 'Sturm',
};

/** Eine Vereinsseite im Sammelalbum: Wappen + Stadion oben, darunter Spieler nach Position. */
@Component({
  selector: 'app-album-club-page',
  standalone: false,
  templateUrl: './album-club-page.component.html',
  styleUrl: './album-club-page.component.scss',
})
export class AlbumClubPageComponent {
  private album = inject(StickerAlbumService);

  club = input.required<AlbumClub>();
  stickers = input.required<Sticker[]>();
  collection = input.required<Collection>();
  theme = input.required<SeasonTheme>();
  open = output<AlbumSlot>();

  /** Slot-Daten einmal je Seite/Sammelstand — stabile Objekte (die Karte setzt sonst Bildfehler-Zustände zurück). */
  slots = computed<AlbumSlot[]>(() => {
    const col = this.collection();
    return this.stickers().map(s => {
      const count = col.counts[s.idx];
      return {
        sticker: s,
        count,
        card: count > 0 ? this.album.cardData(s, col.holo[s.idx]) : null,
        initials: initials(s),
        tilt: ((hashSeed(s.id) % 7) - 3) * 0.6,
      };
    });
  });

  clubSlots = computed(() => this.slots().filter(x => x.sticker.kind !== 'player'));

  sections = computed(() => {
    const players = this.slots().filter(x => x.sticker.kind === 'player');
    const sections = POSITION_ORDER
      .map(pos => ({ label: SECTION_LABEL[pos], slots: players.filter(x => x.sticker.position === pos) }))
      .filter(sec => sec.slots.length > 0);
    const rest = players.filter(x => !x.sticker.position || !POSITION_ORDER.includes(x.sticker.position));
    return rest.length ? [...sections, { label: 'Weitere', slots: rest }] : sections;
  });

  progress = computed(() => {
    const all = this.slots();
    return { have: all.filter(x => x.count > 0).length, total: all.length };
  });

  logoUrl = computed(() => this.album.clubLogoUrl(this.club()));

  /** Seitenhintergrund: Vereinsfarben als Verlauf + Saison-Schärpe + Punkt-Raster (CSS-Variablen fürs SCSS). */
  pageStyle = computed(() => {
    const c = this.club();
    const t = this.theme();
    const primary = c.primary_color ?? '#8b929e';
    const secondary = c.secondary_color ?? primary;
    return {
      '--page-a': primary,
      '--page-b': secondary,
      // Schärpe in der Zweitfarbe — ist die (fast) weiß, würde sie verschwinden → dann Hauptfarbe
      '--sash': isLight(secondary) ? primary : secondary,
      '--sweep': `${t.sweepAngle}deg`,
      '--sash-image': themeSash(t),
      '--dots-at': cornerPosition(t.dotsCorner),
      '--logo-rotation': `${t.logoRotation}deg`,
    };
  });

  label(s: Sticker): string {
    return s.kind === 'logo' ? 'Wappen' : s.kind === 'stadium' ? 'Stadion' : '';
  }
}
