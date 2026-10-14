import { Injectable, inject } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';

/** Was als Interaktion zählt — jedes Ereignis setzt nur den Zeitstempel, daher ohne Drosselung */
const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'scroll', 'touchstart', 'mousemove'] as const;

/**
 * Wann der Nutzer zuletzt etwas getan hat (Klick/Tippen, Tastatur, Scrollen, Mausbewegung, Navigation,
 * Tab wieder angesehen). ClientInfoService schickt daraus `idle` (Sekunden) im Header X-Client-Info mit —
 * der Heartbeat (SessionTrait::touchSession()) beendet damit Sessions, bei denen nur noch ein offener Tab
 * pollt, aber niemand mehr davorsitzt.
 */
@Injectable({ providedIn: 'root' })
export class UserActivityService {
  private lastInteraction = Date.now();

  constructor() {
    const touch = () => { this.lastInteraction = Date.now(); };
    for (const type of ACTIVITY_EVENTS) {
      document.addEventListener(type, touch, { capture: true, passive: true });
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) touch(); });
    inject(Router).events.subscribe(e => { if (e instanceof NavigationEnd) touch(); });
  }

  /** Sekunden seit der letzten Interaktion */
  idleSeconds(): number {
    return Math.max(0, Math.floor((Date.now() - this.lastInteraction) / 1000));
  }
}
