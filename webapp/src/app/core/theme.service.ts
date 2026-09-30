import { Injectable, effect, signal } from '@angular/core';

export type Theme = 'light' | 'dark';

/** localStorage-Key — muss zum Inline-Script in index.html passen (setzt data-theme schon vor dem App-Start). */
const STORAGE_KEY = 'theme';

/** Farbe der Browser-Leiste (meta theme-color) je Theme = --color-surface aus styles/_palettes.scss */
const THEME_COLOR: Record<Theme, string> = { light: '#ffffff', dark: '#181b21' };

/**
 * Erscheinungsbild (Light/Dark) — pro Gerät in localStorage gespeichert, kein Server-Setting.
 * Setzt data-theme an <html>; die Farben selbst kommen aus den CSS Custom Properties in
 * styles/_themes.scss. Wird in App instanziert, gilt also auch auf /login und /noten.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  readonly theme = signal<Theme>(readStored());

  constructor() {
    effect(() => {
      const theme = this.theme();
      document.documentElement.dataset['theme'] = theme;
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEME_COLOR[theme]);
    });
  }

  setTheme(theme: Theme): void {
    this.theme.set(theme);
    try { localStorage.setItem(STORAGE_KEY, theme); } catch { /* Speicher blockiert — gilt dann nur für diese Sitzung */ }
  }
}

function readStored(): Theme {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'dark' ? 'dark' : 'light';
  } catch {
    return 'light';
  }
}
