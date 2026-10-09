import { Injectable, inject, signal } from '@angular/core';
import { catchError, of } from 'rxjs';
import { ApiService } from './api.service';

/** Einträge rechts in der Topbar — je Nutzer einstellbar, getrennt für Desktop und Handy */
export type TopbarItem = 'lukaten' | 'karte' | 'klebrigsten' | 'achievements' | 'benachrichtigungen';
export type TopbarWhere = 'desktop' | 'mobile';
export const TOPBAR_ITEMS: TopbarItem[] = ['lukaten', 'karte', 'klebrigsten', 'achievements', 'benachrichtigungen'];

export function topbarKey(item: TopbarItem, where: TopbarWhere): string {
  return `topbar_${where}_${item}`;
}

/** Standard wie im Backend (AppearanceTrait): am Desktop alle Einträge, auf dem Handy nur Lukaten */
export function topbarDefault(item: TopbarItem, where: TopbarWhere): boolean {
  return where === 'desktop' || item === 'lukaten';
}

/**
 * Erscheinungs-Einstellungen des Nutzers (GET/PATCH /appearance, Tabelle appearance_preference): wie die App für
 * ihn aussieht, unabhängig vom Gerät — getrennt von den Benachrichtigungs-Einstellungen. Bisher: welche Einträge
 * rechts in der Topbar stehen; Abgewähltes liegt im Benutzermenü. Hell/Dunkel bleibt pro Gerät (ThemeService).
 * Bis die Einstellungen geladen sind (und ohne Tabelle) gilt der Standard.
 */
@Injectable({ providedIn: 'root' })
export class AppearanceService {
  private api = inject(ApiService);
  private prefs = signal<Record<string, boolean>>({});

  /** Beim App-Start (Shell) und beim Öffnen der Einstellungen. */
  load(): void {
    this.api.get<Record<string, boolean>>('appearance')
      .pipe(catchError(() => of({} as Record<string, boolean>)))
      .subscribe(p => this.prefs.set(p ?? {}));
  }

  /** Gespeicherter Wert dieses Schalters, sonst der Standard */
  topbarPref(item: TopbarItem, where: TopbarWhere): boolean {
    return this.prefs()[topbarKey(item, where)] ?? topbarDefault(item, where);
  }

  /**
   * Steht der Eintrag auf dieser Breite in der Topbar? Sonst liegt er im Benutzermenü. Auf dem Handy ist nur für
   * einen Eintrag Platz: dort gilt der erste gewählte (die Einstellungen lassen ohnehin nur einen zu).
   */
  topbarShows(item: TopbarItem, where: TopbarWhere): boolean {
    if (where === 'mobile') return TOPBAR_ITEMS.find(i => this.topbarPref(i, 'mobile')) === item;
    return this.topbarPref(item, where);
  }

  setTopbar(item: TopbarItem, where: TopbarWhere, enabled: boolean): void {
    const key = topbarKey(item, where);
    this.prefs.update(p => ({ ...p, [key]: enabled }));
    this.api.patch('appearance', { key, enabled }).subscribe({ error: () => {} });
  }
}
