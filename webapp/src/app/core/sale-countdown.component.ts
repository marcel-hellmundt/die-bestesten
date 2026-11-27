import { Component, DestroyRef, computed, inject, input, signal } from '@angular/core';

/**
 * Live-Countdown bis zu einem Zeitpunkt (ISO 8601), sekundengenau als "MM:SS" (ab einer Stunde "H:MM:SS").
 * Für den Nacht-Sale im Shop (Banner in der Shell + Card auf /klebrigsten/shop).
 */
@Component({
  selector: 'app-sale-countdown',
  standalone: true,
  template: `<span class="countdown" [attr.aria-label]="'noch ' + text()">{{ text() }}</span>`,
  styles: [':host { font-variant-numeric: tabular-nums; }'],
})
export class SaleCountdownComponent {
  until = input.required<string>();

  private now = signal(Date.now());

  constructor() {
    const id = setInterval(() => this.now.set(Date.now()), 1000);
    inject(DestroyRef).onDestroy(() => clearInterval(id));
  }

  text = computed(() => {
    const left = Math.max(0, Math.floor((Date.parse(this.until()) - this.now()) / 1000));
    const h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
    const mm = String(m).padStart(2, '0'), ss = String(s).padStart(2, '0');
    return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
  });
}
