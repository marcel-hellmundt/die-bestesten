import { Component, Signal, computed, inject } from '@angular/core';
import { ThemeService } from './theme.service';

/**
 * Mond-Emoji passend zum angezeigten Theme (hell 🌕, dunkel 🌑) — für Texte, Platzhalter und Tooltips, in denen
 * der Mondpreis vorkommt. Im Feld-Initializer einer Komponente aufrufen (braucht den Injection-Kontext).
 */
export function moonEmoji(): Signal<string> {
  const theme = inject(ThemeService).theme;
  return computed(() => theme() === 'dark' ? '🌑' : '🌕');
}

/**
 * Mondpreis: der Spieler hat noch keinen Marktwert (player_in_season.price NULL — der Datenanbieter hat
 * noch keinen geliefert). Steht anstelle des Preises; solche Spieler sind nicht kaufbar.
 * Hell 🌕 (Vollmond), dunkel 🌑 (Neumond) — folgt dem angezeigten Theme.
 * Einsatz: `@if (price == null) { <app-moon-price /> } @else { …Preis… }`.
 */
@Component({
  selector: 'app-moon-price',
  standalone: true,
  template: `<span class="moon-price" title="Mondpreis — noch kein Marktwert" aria-label="Mondpreis, noch kein Marktwert">{{ moon() }}</span>`,
  styles: [`.moon-price { cursor: help; font-style: normal; }`],
})
export class MoonPriceComponent {
  moon = moonEmoji();
}
