import { Component, HostListener, input, output } from '@angular/core';
import { StickerCardData } from './sticker-card.component';

/**
 * Sticker-Karte frei schwebend in der Bildschirmmitte über abgedunkeltem Hintergrund —
 * interaktiv (Neigung per Maus bzw. Geräteneigung). Schließt per Klick daneben oder Esc.
 * Vor dem Öffnen im Click-Handler requestTiltPermission() aufrufen (iOS-Sensorfreigabe).
 */
@Component({
  selector: 'app-sticker-card-dialog',
  standalone: false,
  template: `
    <div class="backdrop" (click)="closed.emit()">
      <div class="float" (click)="$event.stopPropagation()">
        <app-sticker-card [data]="data()" [interactive]="true" />
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
    }
    .float {
      /* hochkant 5:7 — Breite so, dass die Karte auch in der Höhe auf den Screen passt */
      width: min(72vw, 340px, calc(80vh * 5 / 7));
      animation: pop-in 260ms cubic-bezier(0.2, 0.9, 0.3, 1.2);
    }
    @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
    @keyframes pop-in { from { transform: scale(0.6) rotate(-6deg); opacity: 0; } to { transform: none; opacity: 1; } }
  `],
})
export class StickerCardDialogComponent {
  data = input.required<StickerCardData>();
  closed = output<void>();

  @HostListener('document:keydown.escape')
  onEscape(): void { this.closed.emit(); }
}
