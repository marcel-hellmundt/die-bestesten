import { Component, DestroyRef, HostListener, inject, input, output } from '@angular/core';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { Sticker } from './album.model';

export interface PackCard {
  sticker: Sticker;
  card: StickerCardData;
  isNew: boolean;
  count: number;   // Anzahl nach diesem Pack (inkl. Doppelter)
}

/**
 * Geöffnetes Pack: die gezogenen Karten erscheinen nacheinander (umdrehen), mit "Neu"/"Doppelt"-Marke.
 * Klick auf eine Karte → große Karte (open); "Nächstes Pack" öffnet direkt das nächste ungeöffnete.
 */
@Component({
  selector: 'app-pack-open-dialog',
  standalone: false,
  template: `
    <div class="backdrop" (click)="closed.emit()">
      <div class="panel" (click)="$event.stopPropagation()">
        <h2 class="panel__title">{{ title() }}</h2>
        <div class="cards">
          @for (c of cards(); track $index) {
            <button class="pull" type="button" [style.animation-delay.ms]="$index * 380" (click)="open.emit(c)"
                    [attr.aria-label]="c.sticker.displayname">
              <app-sticker-card [data]="c.card" />
              <span class="tag" [class.tag--new]="c.isNew">{{ c.isNew ? 'Neu' : 'Doppelt ×' + c.count }}</span>
            </button>
          }
        </div>
        <div class="actions">
          @if (remaining() > 0) {
            <button class="btn btn-primary" type="button" [disabled]="busy()" (click)="next.emit()">
              {{ busy() ? 'Öffnet…' : 'Nächstes Pack öffnen (' + remaining() + ')' }}
            </button>
          }
          <button class="btn btn-ghost" type="button" (click)="closed.emit()">Fertig</button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .backdrop {
      position: fixed;
      inset: 0;
      z-index: 400; /* $z-modal — die große Karte (sticker-card-dialog) liegt danach im DOM und damit darüber */
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      background: rgba(0, 0, 0, 0.78);
      animation: fade-in 180ms ease;
      touch-action: none;
      overscroll-behavior: contain;
    }
    .panel {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 20px;
      width: min(100%, 720px);
    }
    .panel__title {
      margin: 0;
      color: #fff;
      font-family: 'Euclid', sans-serif;
      font-size: 22px;
      font-weight: 800;
      font-style: italic;
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }
    .cards {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 16px;
      width: 100%;
    }
    .pull {
      position: relative;
      width: min(200px, calc((100% - 32px) / 3));
      padding: 0;
      border: none;
      background: none;
      cursor: pointer;
      text-align: left;
      perspective: 800px;
      /* backwards: während der Verzögerung unsichtbar, danach wieder normale Styles (Hover funktioniert) */
      animation: reveal 520ms cubic-bezier(0.2, 0.9, 0.3, 1.15) backwards;
      transition: transform 150ms ease;
    }
    .pull:hover { transform: translateY(-4px); }
    .tag {
      position: absolute;
      left: 50%;
      bottom: -10px;
      transform: translateX(-50%);
      padding: 2px 10px;
      border-radius: 9999px;
      background: #374151;
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }
    .tag--new { background: #16a34a; }
    .actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 8px;
      margin-top: 8px;
    }
    .actions .btn-ghost { color: #fff; }
    @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
    @keyframes reveal {
      from { opacity: 0; transform: rotateY(90deg) scale(0.8); }
      to   { opacity: 1; transform: none; }
    }
  `],
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

  private prevOverflow = document.body.style.overflow;

  constructor() {
    document.body.style.overflow = 'hidden';
    inject(DestroyRef).onDestroy(() => { document.body.style.overflow = this.prevOverflow; });
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (!this.covered()) this.closed.emit();
  }
}
