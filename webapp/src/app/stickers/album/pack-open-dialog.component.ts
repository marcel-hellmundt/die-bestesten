import { Component, DestroyRef, HostListener, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { PackCard, PackInfo, packFace, packRules } from './pack.model';

/** Dauer der Aufreiß-Animation bis zum Aufdecken (muss zu den Delays im SCSS passen). */
const TEAR_MS = 1750;

/**
 * Pack-Dialog: zuerst das geschlossene Folien-Pack (Art + Anlass aufgedruckt, Farbe je Art) mit
 * "Tippen zum Aufreißen" und "Später öffnen". Tippen startet die Aufreiß-Animation und meldet `tear` —
 * erst dann öffnet der Aufrufer das Pack (Server bzw. Test) und reicht `cards` nach; aufgedeckt wird,
 * sobald Animation UND Karten da sind. Klick auf eine Karte → große Karte (open).
 */
@Component({
  selector: 'app-pack-open-dialog',
  standalone: false,
  templateUrl: './pack-open-dialog.component.html',
  styleUrl: './pack-open-dialog.component.scss',
})
export class PackOpenDialogComponent {
  heading = input('');
  pack = input.required<PackInfo>();
  /** null, solange das Pack noch nicht geöffnet ist */
  cards = input<PackCard[] | null>(null);
  error = input<string | null>(null);
  remaining = input(0);
  busy = input(false);
  /** true, solange darüber die große Karte offen ist — Esc schließt dann nur die. */
  covered = input(false);
  /** "Nicht mehr anzeigen" anbieten (nur in der Einblendung, wenn jemand Packs wiederholt ignoriert) */
  offerOptOut = input(false);

  tear = output<void>();
  optOut = output<void>();
  next = output<void>();
  open = output<PackCard>();
  closed = output<void>();

  face = computed(() => packFace(this.pack()));
  /** "3 Sticker" bzw. "5 neue Sticker", wenn die Pack-Art nur garantiert neue Karten enthält */
  countLabel = computed(() => {
    const p = this.pack();
    return `${p.size} ${packRules(p.source).allNew ? 'neue ' : ''}Sticker`;
  });
  backs = computed(() => Array.from({ length: this.pack().size }, (_, i) => i));

  private torn = signal(false);
  private timerDone = signal(false);
  phase = computed<'sealed' | 'tearing' | 'revealed'>(() =>
    !this.torn() ? 'sealed' : this.timerDone() && this.cards() ? 'revealed' : 'tearing'
  );

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
      this.pack();
      untracked(() => {
        if (this.tearTimer) clearTimeout(this.tearTimer);
        this.torn.set(false);
        this.timerDone.set(false);
      });
    });
  }

  /** nach "Nicht mehr anzeigen": Bestätigung statt Pack */
  optedOut = signal(false);

  onOptOut(): void {
    this.optedOut.set(true);
    this.optOut.emit();
  }

  onTear(): void {
    if (this.torn()) return;
    this.torn.set(true);
    this.tear.emit();
    if (this.reducedMotion) { this.timerDone.set(true); return; }
    this.tearTimer = setTimeout(() => this.timerDone.set(true), TEAR_MS);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (!this.covered()) this.closed.emit();
  }
}
