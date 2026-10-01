import {
  Component, DestroyRef, ElementRef, HostListener, afterNextRender, computed, effect, inject, input, output, signal,
  untracked, viewChild,
} from '@angular/core';
import { PACK_ART, PackCard, PackInfo, packCountLabel, packDesign, packFace } from './pack.model';
import { HOLO_LABEL } from '../sticker-card/sticker-card.component';

/** Dauer der Aufreiß-Animation bis zum Aufdecken (mindestens; muss zu den Delays im SCSS passen). */
const TEAR_MS = 1750;
/** Herausziehen der verdeckten Karten (.pack--tearing .back → rise im SCSS): Start, Versatz je Karte, Dauer */
const RISE_START_MS = 820;
const RISE_STAGGER_MS = 110;
const RISE_MS = 650;
/** Austeilen: jede Karte fliegt verdeckt vom Pack an ihren Platz, versetzt um DEAL_STAGGER_MS */
const DEAL_MS = 460;
const DEAL_STAGGER_MS = 80;

/**
 * Pack-Dialog: zuerst das geschlossene Folien-Pack (Art + Anlass aufgedruckt, Farbe je Art) mit
 * "Tippen zum Aufreißen" und "Später öffnen". Tippen startet die Aufreiß-Animation und meldet `tear` —
 * erst dann öffnet der Aufrufer das Pack (Server bzw. Test) und reicht `cards` nach; ausgeteilt wird,
 * sobald Animation UND Karten da sind. Die Karten liegen verdeckt — Klick dreht eine um, zweiter Klick
 * → große Karte (open).
 */
@Component({
  selector: 'app-pack-open-dialog',
  standalone: false,
  templateUrl: './pack-open-dialog.component.html',
  styleUrl: './pack-open-dialog.component.scss',
  // Kartenrückseite (Muster) + ihr Relief (Rahmen-Fase + Logo-Kuppel, Prinzip wie die Holo-Facetten) — relativ zur
  // base href, wie in sticker-card, damit der Build die URLs nicht auflöst
  host: {
    style: '--back-face: url(img/stickers/card-back.svg); --back-relief: url(img/stickers/holo/card-back-relief.svg)',
  },
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
  /** ohne Abdunklung/Overlay direkt in der Seite (Admin-Testseite /klebrigsten/packs) */
  inline = input(false);
  /** ab so vielen Einträgen wird die Auswahl klein + scrollbar */
  compactAfter = input(6);
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
  /** Design (Farbe + Bild): Pack-Art bei Shop-Packs, sonst die Quelle → Klasse pack--{design} */
  readonly design = packDesign;
  readonly countLabel = packCountLabel;
  artOf(p: PackInfo) { return PACK_ART[packDesign(p)] ?? null; }

  /**
   * Auswahl gruppiert: gleiche Packs (gleiche Art + Anlass, z.B. 25 aus einer Kiste) als ein Stapel "×N" —
   * index = erstes Pack der Gruppe in choices (das wird beim Antippen geöffnet).
   */
  groups = computed(() => {
    const out: { info: PackInfo; index: number; count: number; key: string }[] = [];
    this.choices().forEach((c, index) => {
      const key = [c.source, c.kind, c.clubId, c.milestonePoints, c.matchdayNumber, c.leagueName, c.size].join('|');
      const g = out.find(x => x.key === key);
      if (g) g.count++; else out.push({ info: c, index, count: 1, key });
    });
    return out;
  });
  /** angedeutete Packs hinter dem vordersten (max. 2) */
  stackBehind(count: number): number[] { return count >= 3 ? [2, 1] : count === 2 ? [1] : []; }
  backs = computed(() => Array.from({ length: this.pack()?.size ?? 0 }, (_, i) => i));

  private torn = signal(false);
  private timerDone = signal(false);
  phase = computed<'sealed' | 'tearing' | 'revealed'>(() =>
    !this.torn() ? 'sealed' : this.timerDone() && this.cards() ? 'revealed' : 'tearing'
  );

  private tearTimer: ReturnType<typeof setTimeout> | null = null;
  private reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  private host = inject<ElementRef<HTMLElement>>(ElementRef);
  private packEl = viewChild<ElementRef<HTMLElement>>('packEl');
  /** Mitte der Öffnung beim Aufreißen — Fallback-Startpunkt fürs Austeilen */
  private dealOrigin: { x: number; y: number } | null = null;
  /** Lage jeder herausgezogenen, verdeckten Karte beim Übergang — dort startet die ausgeteilte Karte (kein Bruch) */
  private dealFrom: { x: number; y: number; w: number }[] = [];

  /** Positionen der herausgezogenen Karten merken, bevor das Pack durch die ausgeteilten Karten ersetzt wird */
  private captureBacks(): void {
    const backs = Array.from(this.host.nativeElement.querySelectorAll<HTMLElement>('.pack__cards .back'));
    this.dealFrom = backs.map(b => {
      const r = b.getBoundingClientRect(); // Mitte stimmt auch bei gedrehter Karte
      return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: b.offsetWidth };
    });
  }

  /** Karten bleiben nach dem Austeilen verdeckt und werden erst per Klick einzeln umgedreht */
  /** Holo-Karten beim Aufdecken eigens kennzeichnen (Label + Leuchtrahmen) — der Folien-Schimmer der Karte selbst
   *  hängt an Blend-Modi, die mobile Browser in der 3D-Dreh-Animation oft nicht darstellen */
  readonly holoLabel = HOLO_LABEL;

  flipped = signal<ReadonlySet<number>>(new Set());
  isHidden(i: number): boolean { return !this.flipped().has(i); }

  onPull(c: PackCard, i: number): void {
    if (this.isHidden(i)) { this.flipped.update(s => new Set(s).add(i)); return; }
    this.open.emit(c);
  }

  /** Alle Karten ausgeteilt → erst dann Hinweis + Buttons einblenden */
  revealTotal = computed(() => {
    const n = this.cards()?.length ?? 0;
    if (this.reducedMotion || !n) return 0;
    return (n - 1) * DEAL_STAGGER_MS + DEAL_MS;
  });

  /**
   * Karten verdeckt an ihre Plätze fliegen lassen (FLIP: Endposition messen, von der Lage der herausgezogenen
   * Karte aus animieren — gleiche Mitte, Größe und Neigung, damit der Übergang nahtlos ist).
   */
  private deal(): void {
    if (this.reducedMotion) return;
    const pulls = Array.from(this.host.nativeElement.querySelectorAll<HTMLElement>('.pull'));
    const n = pulls.length;
    pulls.forEach((el, i) => {
      const from = this.dealFrom[i] ?? (this.dealOrigin ? { ...this.dealOrigin, w: el.offsetWidth * 0.55 } : null);
      if (!from) return;
      const r = el.getBoundingClientRect();
      const dx = from.x - (r.left + r.width / 2);
      const dy = from.y - (r.top + r.height / 2);
      const scale = from.w / el.offsetWidth;
      const tilt = (i - (n - 1) / 2) * 7; // wie der Fächer beim Herausziehen (rise im SCSS)
      el.animate(
        [
          { transform: `translate(${dx}px, ${dy}px) rotate(${tilt}deg) scale(${scale})` },
          { transform: 'none' },
        ],
        { duration: DEAL_MS, delay: i * DEAL_STAGGER_MS, easing: 'cubic-bezier(0.2, 0.8, 0.3, 1.05)', fill: 'backwards' },
      );
    });
  }
  private prevOverflow = document.body.style.overflow;

  constructor() {
    // Seite hinter dem Overlay nicht scrollen (inline: nichts sperren)
    afterNextRender(() => { if (!this.inline()) document.body.style.overflow = 'hidden'; });
    inject(DestroyRef).onDestroy(() => {
      if (!this.inline()) document.body.style.overflow = this.prevOverflow;
      if (this.tearTimer) clearTimeout(this.tearTimer);
    });

    // Jedes neue Pack ("Nächstes Pack öffnen") beginnt wieder geschlossen
    effect(() => {
      this.pack();
      untracked(() => {
        if (this.tearTimer) clearTimeout(this.tearTimer);
        this.torn.set(false);
        this.timerDone.set(false);
        this.flipped.set(new Set());
      });
    });

    // Aufgedeckt: sobald die Karten gerendert sind, austeilen
    effect(() => {
      if (this.phase() !== 'revealed') return;
      untracked(() => requestAnimationFrame(() => this.deal()));
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
    // Öffnung oben am Pack (dort stehen die herausgezogenen Karten) = Startpunkt fürs Austeilen
    const r = this.packEl()?.nativeElement.getBoundingClientRect();
    this.dealOrigin = r ? { x: r.left + r.width / 2, y: r.top + r.height * 0.3 } : null;
    this.torn.set(true);
    this.tear.emit();
    if (this.reducedMotion) { this.timerDone.set(true); return; }
    // Übergang erst, wenn auch die letzte Karte ganz herausgezogen ist (Big Pack: 7 Karten)
    const n = this.pack()?.size ?? 0;
    const tearMs = Math.max(TEAR_MS, RISE_START_MS + Math.max(0, n - 1) * RISE_STAGGER_MS + RISE_MS + 80);
    this.tearTimer = setTimeout(() => {
      this.captureBacks(); // die verdeckten Karten stehen jetzt still — ihre Lage ist der Startpunkt fürs Austeilen
      this.timerDone.set(true);
    }, tearMs);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (!this.covered() && !this.inline()) this.closed.emit();
  }
}
