import { Injectable, signal } from '@angular/core';

/**
 * Vorschau des neuen Lukaten-Modus (Konto je Manager, siehe docs/lukaten-economy-concept.md) — pro Gerät
 * (localStorage-Key `lukaten-preview`), Schalter unter /verwaltung/lukaten. Eingeschaltet hängt ApiService an jeden
 * Request den Header X-Lukaten-Preview; die API beachtet ihn nur für Admins und nur, wo ihre .env es erlaubt
 * (Development). So bleibt die Vorschau auf einen Admin und eine Umgebung beschränkt, obwohl sich beide
 * Umgebungen die Datenbank teilen.
 */
@Injectable({ providedIn: 'root' })
export class LukatenPreviewService {
  private static readonly KEY = 'lukaten-preview';

  readonly enabled = signal(this.load());

  private load(): boolean {
    try {
      return localStorage.getItem(LukatenPreviewService.KEY) === '1';
    } catch {
      return false;
    }
  }

  set(on: boolean): void {
    this.enabled.set(on);
    try {
      if (on) localStorage.setItem(LukatenPreviewService.KEY, '1');
      else localStorage.removeItem(LukatenPreviewService.KEY);
    } catch {}
  }
}
