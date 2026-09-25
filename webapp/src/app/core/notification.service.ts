import { Injectable, inject, signal } from '@angular/core';
import { Subscription, catchError, interval, of, startWith, switchMap } from 'rxjs';
import { ApiService } from './api.service';

export interface AppNotification {
  id: string;
  sender_id: string | null;
  sender_name: string | null;
  receiver_id: string;
  title: string;
  message: string | null;
  created_at: string;
  read_at: string | null;
}

export type NotificationPreferences = Record<string, boolean>;

@Injectable({ providedIn: 'root' })
export class NotificationService {
  private api = inject(ApiService);
  private loaded = false;

  private _notifications = signal<AppNotification[]>([]);
  private _preferences = signal<NotificationPreferences>({});
  private _unreadCount = signal<number>(0);
  // Offene Direktangebote anderer Manager für eigene Spieler (kommt mit dem Polling, für den Menü-Hinweis)
  private _incomingOffers = signal<number>(0);

  private _preferencesLoaded = signal(false);

  notifications = this._notifications.asReadonly();
  preferences = this._preferences.asReadonly();
  /** true, sobald die Einstellungen (einmal) geladen sind — Einblendungen warten darauf, damit nichts aufblitzt */
  preferencesLoaded = this._preferencesLoaded.asReadonly();
  unreadCount = this._unreadCount.asReadonly();
  incomingOffers = this._incomingOffers.asReadonly();

  private pollSub?: Subscription;
  private visibilityListenerAdded = false;

  // Läuft nur, während der Tab sichtbar ist — ein im Hintergrund offen gelassener Tab soll den
  // Heartbeat (siehe api/app/guard.php touchSession()) nicht künstlich am Leben halten und damit
  // eine "Session" vortäuschen, die niemand aktiv nutzt.
  private onVisibilityChange = (): void => {
    if (document.hidden) {
      this.stopPolling();
    } else {
      this.startPolling();
    }
  };

  startPolling(): void {
    if (!this.visibilityListenerAdded) {
      document.addEventListener('visibilitychange', this.onVisibilityChange);
      this.visibilityListenerAdded = true;
    }
    if (this.pollSub || document.hidden) return;
    this.pollSub = interval(4000)
      .pipe(
        startWith(0), // beim (Wieder-)Start sofort abfragen, nicht erst nach 4s
        switchMap(() =>
          this.api
            .get<{ count: number; incoming_offers?: number }>('notification/unread_count')
            .pipe(catchError(() => of({ count: this._unreadCount(), incoming_offers: this._incomingOffers() }))),
        ),
      )
      .subscribe(({ count, incoming_offers }) => {
        this._unreadCount.set(count);
        this._incomingOffers.set(incoming_offers ?? 0);
      });
  }

  stopPolling(): void {
    this.pollSub?.unsubscribe();
    this.pollSub = undefined;
  }

  load(): void {
    if (this.loaded) return;
    this.loaded = true;
    this.api
      .get<AppNotification[]>('notification')
      .pipe(catchError(() => of([] as AppNotification[])))
      .subscribe((ns) => {
        this._notifications.set(ns);
        this._unreadCount.set(ns.filter((n) => !n.read_at).length);
      });
  }

  reload(): void {
    this.loaded = false;
    this.load();
  }

  loadPreferences(): void {
    this.api
      .get<NotificationPreferences>('notification/preferences')
      .pipe(catchError(() => of({} as NotificationPreferences)))
      .subscribe((prefs) => {
        this._preferences.set(prefs);
        this._preferencesLoaded.set(true);
      });
  }

  /** Einstellung aktiv? Fehlender Eintrag = an (wie im Backend). */
  isEnabled(eventType: string): boolean {
    return this._preferences()[eventType] ?? true;
  }

  /** Einblendung (overlay_achievement / overlay_pack) erlaubt — erst nachdem die Einstellungen geladen sind. */
  overlayAllowed(eventType: 'overlay_achievement' | 'overlay_pack'): boolean {
    return this._preferencesLoaded() && this.isEnabled(eventType);
  }

  setPreference(eventType: string, enabled: boolean): void {
    this._preferences.update((p) => ({ ...p, [eventType]: enabled }));
    this.api.patch<any>('notification/preferences', { event_type: eventType, enabled }).subscribe();
  }

  markAsRead(id: string): void {
    const wasUnread = !this._notifications().find((n) => n.id === id)?.read_at;
    this.api.patch<any>(`notification/${id}`, {}).subscribe(() => {
      this._notifications.update((ns) =>
        ns.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n)),
      );
      if (wasUnread) this._unreadCount.update((c) => Math.max(0, c - 1));
    });
  }

  markAllAsRead(): void {
    this.api.patch<any>('notification/read_all', {}).subscribe(() => {
      const now = new Date().toISOString();
      this._notifications.update((ns) => ns.map((n) => ({ ...n, read_at: n.read_at ?? now })));
      this._unreadCount.set(0);
    });
  }
}
