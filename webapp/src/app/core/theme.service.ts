import { DestroyRef, Injectable, computed, effect, inject, signal } from '@angular/core';

/** Tatsächlich angezeigtes Theme. */
export type Theme = 'light' | 'dark';
/** Wahl des Nutzers — 'system' folgt der Einstellung des Geräts (prefers-color-scheme), auch live. */
export type ThemePreference = Theme | 'system';

/** localStorage-Key — muss zum Inline-Script in index.html passen (setzt data-theme schon vor dem App-Start). */
const STORAGE_KEY = 'theme';

/** Farbe der Browser-Leiste (meta theme-color) je Theme = --color-surface aus styles/_palettes.scss */
const THEME_COLOR: Record<Theme, string> = { light: '#ffffff', dark: '#181b21' };

/**
 * Erscheinungsbild (Hell/Dunkel/System) — pro Gerät in localStorage gespeichert, kein Server-Setting.
 * Setzt data-theme an <html>; die Farben selbst kommen aus den CSS Custom Properties in
 * styles/_themes.scss. Wird in App instanziert, gilt also auch auf /login und /noten.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  readonly preference = signal<ThemePreference>(readStored());

  private readonly media = window.matchMedia('(prefers-color-scheme: dark)');
  /** Aktuelle Einstellung des Geräts */
  readonly systemTheme = signal<Theme>(this.media.matches ? 'dark' : 'light');

  readonly theme = computed<Theme>(() => {
    const pref = this.preference();
    return pref === 'system' ? this.systemTheme() : pref;
  });

  constructor() {
    const onChange = (e: MediaQueryListEvent) => this.systemTheme.set(e.matches ? 'dark' : 'light');
    this.media.addEventListener('change', onChange);
    inject(DestroyRef).onDestroy(() => this.media.removeEventListener('change', onChange));

    effect(() => {
      const theme = this.theme();
      document.documentElement.dataset['theme'] = theme;
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEME_COLOR[theme]);
    });
  }

  setPreference(pref: ThemePreference): void {
    this.preference.set(pref);
    try { localStorage.setItem(STORAGE_KEY, pref); } catch { /* Speicher blockiert — gilt dann nur für diese Sitzung */ }
  }
}

function readStored(): ThemePreference {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored === 'dark' || stored === 'system' ? stored : 'light';
  } catch {
    return 'light';
  }
}
