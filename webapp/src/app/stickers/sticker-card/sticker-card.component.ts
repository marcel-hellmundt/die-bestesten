import { Component, DestroyRef, ElementRef, computed, effect, inject, input, signal } from '@angular/core';

export type StickerTier = 'common' | 'rare' | 'epic' | 'legendary';

/** Alles, was eine Sticker-Karte zum Rendern braucht. */
export interface StickerCardData {
  displayname: string;
  firstName?: string | null;   // klein über dem Namen (wie auf Panini-/Topps-Stickern)
  photoUrl: string | null;
  clubLogoUrl: string | null;
  clubName?: string;
  tier: StickerTier;
}

const MAX_TILT = 18; // Grad

/**
 * iOS (13+) liefert deviceorientation erst nach expliziter Erlaubnis — die Anfrage MUSS
 * synchron aus einer Nutzer-Geste (Klick/Tap) heraus gestartet werden, deshalb vom Aufrufer
 * direkt im Click-Handler aufrufen, bevor die Karte geöffnet wird. Andere Plattformen: no-op.
 */
export function requestTiltPermission(): void {
  const D = (window as any).DeviceOrientationEvent;
  if (D && typeof D.requestPermission === 'function') {
    D.requestPermission().catch(() => { /* abgelehnt → Karte bleibt einfach gerade */ });
  }
}

/**
 * Die eigentliche Sticker-Karte (hochkant). Statisch (Sammelalbum, `interactive` = false) oder
 * interaktiv: dann neigt sie sich auf Desktop mit der Maus, auf Touch-Geräten mit der
 * Geräteneigung (deviceorientation, erster Messwert = Nullstellung) — inkl. Glanzlicht.
 * Die Größe bestimmt der Container (Breite), die Höhe folgt aus dem Seitenverhältnis 5:7.
 */
@Component({
  selector: 'app-sticker-card',
  standalone: false,
  templateUrl: './sticker-card.component.html',
  styleUrl: './sticker-card.component.scss',
})
export class StickerCardComponent {
  data = input.required<StickerCardData>();
  interactive = input(false);

  private host = inject(ElementRef<HTMLElement>);

  /** Kleine Zeile (Vorname) — nur wenn vorhanden und nicht schon im Anzeigenamen enthalten. */
  firstLine = computed(() => {
    const { firstName, displayname } = this.data();
    if (!firstName || displayname.toLowerCase().includes(firstName.toLowerCase())) return null;
    return firstName;
  });

  /** Große Zeile: Anzeigename, ein führendes Initial ("J. Hofmann") entfällt, wenn der Vorname darüber steht. */
  mainLine = computed(() => {
    const { displayname } = this.data();
    return this.firstLine() ? displayname.replace(/^[A-ZÄÖÜ]\.\s+/, '') : displayname;
  });

  /** Schriftgröße der großen Zeile in cqw — lange Namen ("Chukwuemeka") schrumpfen statt abgeschnitten zu werden. */
  mainSize = computed(() => {
    const len = this.mainLine().length;
    return len <= 8 ? 10.5 : Math.max(5.5, (10.5 * 8) / len);
  });

  rotateX = signal(0);
  rotateY = signal(0);
  glareX = signal(50); // % — Position des Glanzlichts
  glareY = signal(30);
  photoFailed = signal(false);
  logoFailed = signal(false);

  constructor() {
    const destroyRef = inject(DestroyRef);
    let cleanup: (() => void) | null = null;

    effect(() => {
      cleanup?.();
      cleanup = null;
      if (!this.interactive()) { this.reset(); return; }
      const coarse = window.matchMedia?.('(pointer: coarse)').matches;
      cleanup = coarse && 'DeviceOrientationEvent' in window ? this.listenOrientation() : this.listenPointer();
    });
    destroyRef.onDestroy(() => cleanup?.());
  }

  private reset(): void {
    this.rotateX.set(0); this.rotateY.set(0); this.glareX.set(50); this.glareY.set(30);
  }

  private apply(nx: number, ny: number): void {
    // nx/ny ∈ [-1, 1]: Auslenkung horizontal/vertikal
    const cx = Math.max(-1, Math.min(1, nx));
    const cy = Math.max(-1, Math.min(1, ny));
    this.rotateY.set(cx * MAX_TILT);
    this.rotateX.set(-cy * MAX_TILT);
    this.glareX.set(50 + cx * 40);
    this.glareY.set(50 + cy * 40);
  }

  /** Desktop: Mausposition relativ zur Kartenmitte, normiert auf die halbe Viewport-Größe. */
  private listenPointer(): () => void {
    const onMove = (e: PointerEvent) => {
      const r = (this.host.nativeElement as HTMLElement).getBoundingClientRect();
      const nx = (e.clientX - (r.left + r.width / 2)) / (window.innerWidth / 2);
      const ny = (e.clientY - (r.top + r.height / 2)) / (window.innerHeight / 2);
      this.apply(nx, ny);
    };
    window.addEventListener('pointermove', onMove);
    return () => window.removeEventListener('pointermove', onMove);
  }

  /** Mobile: Geräteneigung, relativ zur Haltung beim Öffnen (erster Messwert = gerade). */
  private listenOrientation(): () => void {
    let base: { beta: number; gamma: number } | null = null;
    const range = 25; // Grad Geräteneigung für volle Kartenauslenkung
    const onOrient = (e: DeviceOrientationEvent) => {
      if (e.beta == null || e.gamma == null) return;
      base ??= { beta: e.beta, gamma: e.gamma };
      this.apply((e.gamma - base.gamma) / range, (e.beta - base.beta) / range);
    };
    window.addEventListener('deviceorientation', onOrient);
    return () => window.removeEventListener('deviceorientation', onOrient);
  }
}
