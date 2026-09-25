import { Component, DestroyRef, HostListener, effect, inject, input, output, signal, untracked } from '@angular/core';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { Sticker } from './album.model';

export interface PackCard {
  sticker: Sticker;
  card: StickerCardData;
  isNew: boolean;
  count: number;   // Anzahl nach diesem Pack (inkl. Doppelter)
}

/** sealed = geschlossenes Pack (Tippen zum Aufreißen) → tearing = Animation → revealed = Karten aufgedeckt */
type Phase = 'sealed' | 'tearing' | 'revealed';

/** Dauer der Aufreiß-Animation bis zum Aufdecken (muss zu den Delays im SCSS passen). */
const TEAR_MS = 1750;

/**
 * Geöffnetes Pack: zuerst das geschlossene Folien-Pack — Tippen reißt es auf (wackeln, Lasche fliegt ab,
 * Karten schieben sich verdeckt heraus), danach erscheinen die Karten nacheinander (umdrehen) mit
 * "Neu"/"Doppelt"-Marke. Klick auf eine Karte → große Karte (open); "Nächstes Pack" öffnet direkt das nächste.
 */
@Component({
  selector: 'app-pack-open-dialog',
  standalone: false,
  templateUrl: './pack-open-dialog.component.html',
  styleUrl: './pack-open-dialog.component.scss',
})
export class PackOpenDialogComponent {
  title = input.required<string>();
  cards = input.required<PackCard[]>();
  remaining = input(0);
  busy = input(false);
  /** true, solange darüber die große Karte offen ist — Esc schließt dann nur die. */
  covered = input(false);

  next = output<void>();
  open = output<PackCard>();
  closed = output<void>();

  phase = signal<Phase>('sealed');
  private tearTimer: ReturnType<typeof setTimeout> | null = null;
  private reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  private prevOverflow = document.body.style.overflow;

  constructor() {
    document.body.style.overflow = 'hidden';
    inject(DestroyRef).onDestroy(() => {
      document.body.style.overflow = this.prevOverflow;
      if (this.tearTimer) clearTimeout(this.tearTimer);
    });

    // Jedes neue Pack ("Nächstes Pack öffnen") beginnt wieder geschlossen
    effect(() => {
      this.cards();
      untracked(() => this.phase.set('sealed'));
    });
  }

  tear(): void {
    if (this.phase() !== 'sealed') return;
    if (this.reducedMotion) { this.phase.set('revealed'); return; }
    this.phase.set('tearing');
    this.tearTimer = setTimeout(() => this.phase.set('revealed'), TEAR_MS);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (!this.covered()) this.closed.emit();
  }
}
