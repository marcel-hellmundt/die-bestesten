import { Component } from '@angular/core';

/**
 * Mondpreis: der Spieler hat noch keinen Marktwert (player_in_season.price NULL — der Datenanbieter hat
 * noch keinen geliefert). Steht anstelle des Preises; solche Spieler sind nicht kaufbar.
 * Einsatz: `@if (price == null) { <app-moon-price /> } @else { …Preis… }`.
 */
@Component({
  selector: 'app-moon-price',
  standalone: true,
  template: `<span class="moon-price" title="Mondpreis — noch kein Marktwert" aria-label="Mondpreis, noch kein Marktwert">🌙</span>`,
  styles: [`.moon-price { cursor: help; font-style: normal; }`],
})
export class MoonPriceComponent {}
