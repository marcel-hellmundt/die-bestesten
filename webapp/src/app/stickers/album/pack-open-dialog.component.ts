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
  /** null = Auswahl anzeigen (choices) statt eines einzelnen Packs */
  pack = input<PackInfo | null>(null);
  /** mehrere neue Packs klein nebeneinander — der Nutzer wählt, welches er zuerst aufreißt */
  choices = input<PackInfo[]>([]);
  /** in der Auswahl: "Nächstes Pack wählen" führt zurück zur Auswahl */
  choosing = input(false);
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
  pick = output<number>();
  back = output<void>();

  readonly faceOf = packFace;

  /**
   * Auswahl gruppiert: gleiche Packs (gleiche Art + Anlass, z.B. 25 aus einer Kiste) als ein Stapel "×N" —
   * index = erstes Pack der Gruppe in choices (das wird beim Antippen geöffnet).
   */
  groups = computed(() => {
    const out: { info: PackInfo; index: number; count: number; key: string }[] = [];
    this.choices().forEach((c, index) => {
      const key = [c.source, c.shopOffer, c.milestonePoints, c.matchdayNumber, c.leagueName, c.size].join('|');
      const g = out.find(x => x.key === key);
      if (g) g.count++; else out.push({ info: c, index, count: 1, key });
    });
    return out;
  });
  /** angedeutete Packs hinter dem vordersten (max. 2) */
  stackBehind(count: number): number[] { return count >= 3 ? [2, 1] : count === 2 ? [1] : []; }
  /** "3 Sticker" bzw. "5 neue Sticker", wenn die Pack-Art nur garantiert neue Karten enthält */
  countLabelOf(p: PackInfo): string {
    return `${p.size} ${packRules(p.source).allNew ? 'neue ' : ''}Sticker`;
  }
  backs = computed(() => Array.from({ length: this.pack()?.size ?? 0 }, (_, i) => i));

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
