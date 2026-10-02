import { Component, DestroyRef, HostListener, inject, input, output } from '@angular/core';
import { HOLO_LABEL, STICKER_TIER_LABEL, StickerCardData } from './sticker-card.component';

/**
 * Sticker-Karte frei schwebend in der Bildschirmmitte über abgedunkeltem Hintergrund —
 * interaktiv (Neigung per Maus bzw. Geräteneigung). Schließt per Klick daneben oder Esc.
 * Auf iOS bleibt sie gerade (keine Sensor-Abfrage, siehe sticker-card.component.ts).
 */
@Component({
  selector: 'app-sticker-card-dialog',
  standalone: false,
  template: `
    <div class="backdrop" (click)="closed.emit()">
      <div class="float" (click)="$event.stopPropagation()">
        <app-sticker-card [data]="data()" [interactive]="true" />
        <!-- Holo-Karten zeigen oben rechts die Variante — die Spieler-Seltenheit (Marktwert) steht hier -->
        @if (data().holo; as holo) {
          <p class="caption">{{ holoLabel[holo] }} · Spieler: {{ tierLabel[data().tier] }}</p>
        }
      </div>
    </div>
  `,
  styles: [`
    .backdrop {
      position: fixed;
      inset: 0;
      z-index: 400; /* $z-modal */
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(0, 0, 0, 0.72);
      animation: fade-in 180ms ease;
      /* iOS Safari ignoriert overflow:hidden am body teilweise — Touch-Scrollen über dem Overlay direkt unterbinden */
      touch-action: none;
      overscroll-behavior: contain;
    }
    .float {
      /* hochkant 5:7 — Breite so, dass die Karte auch in der Höhe auf den Screen passt */
      width: min(72vw, 340px, calc(80vh * 5 / 7));
      animation: pop-in 260ms cubic-bezier(0.2, 0.9, 0.3, 1.2);
    }
    .caption {
      margin: 14px 0 0;
      color: rgba(255, 255, 255, 0.85);
      font-size: 13px;
      font-weight: 600;
      text-align: center;
    }
    @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
    @keyframes pop-in { from { transform: scale(0.6) rotate(-6deg); opacity: 0; } to { transform: none; opacity: 1; } }
  `],
})
export class StickerCardDialogComponent {
  data = input.required<StickerCardData>();
  closed = output<void>();

  readonly holoLabel = HOLO_LABEL;
  readonly tierLabel = STICKER_TIER_LABEL;

  // Hintergrund-Scrollen sperren, solange der Dialog offen ist (gleiches Muster wie bottom-sheet.service.ts);
  // vorherigen Wert merken, falls z.B. ein Bottom-Sheet ihn schon gesetzt hat.
  private prevOverflow = document.body.style.overflow;

  constructor() {
    document.body.style.overflow = 'hidden';
    inject(DestroyRef).onDestroy(() => { document.body.style.overflow = this.prevOverflow; });
  }

  @HostListener('document:keydown.escape')
  onEscape(): void { this.closed.emit(); }
}
