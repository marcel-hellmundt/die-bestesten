import { Injectable, inject, signal } from '@angular/core';
import { Subscription, catchError, interval, of, switchMap } from 'rxjs';
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
  private api    = inject(ApiService);
  private loaded = false;

  private _notifications = signal<AppNotification[]>([]);
  private _preferences   = signal<NotificationPreferences>({});
  private _unreadCount   = signal<number>(0);

  notifications = this._notifications.asReadonly();
  preferences   = this._preferences.asReadonly();
  unreadCount   = this._unreadCount.asReadonly();

  private pollSub?: Subscription;

  startPolling(): void {
    if (this.pollSub) return;
    this.pollSub = interval(1000).pipe(
      switchMap(() => this.api.get<{ count: number }>('notification/unread_count').pipe(
        catchError(() => of({ count: this._unreadCount() }))
      ))
    ).subscribe(({ count }) => this._unreadCount.set(count));
  }

  stopPolling(): void {
    this.pollSub?.unsubscribe();
    this.pollSub = undefined;
  }

  load(): void {
    if (this.loaded) return;
    this.loaded = true;
    this.api.get<AppNotification[]>('notification').pipe(
      catchError(() => of([] as AppNotification[]))
    ).subscribe(ns => {
      this._notifications.set(ns);
      this._unreadCount.set(ns.filter(n => !n.read_at).length);
    });
  }

  reload(): void {
    this.loaded = false;
    this.load();
  }

  loadPreferences(): void {
    this.api.get<NotificationPreferences>('notification/preferences').pipe(
      catchError(() => of({} as NotificationPreferences))
    ).subscribe(prefs => this._preferences.set(prefs));
  }

  setPreference(eventType: string, enabled: boolean): void {
    this._preferences.update(p => ({ ...p, [eventType]: enabled }));
    this.api.patch<any>('notification/preferences', { event_type: eventType, enabled }).subscribe();
  }

  markAsRead(id: string): void {
    const wasUnread = !this._notifications().find(n => n.id === id)?.read_at;
    this.api.patch<any>(`notification/${id}`, {}).subscribe(() => {
      this._notifications.update(ns =>
        ns.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n)
      );
      if (wasUnread) this._unreadCount.update(c => Math.max(0, c - 1));
    });
  }

  markAllAsRead(): void {
    this.api.patch<any>('notification/read_all', {}).subscribe(() => {
      const now = new Date().toISOString();
      this._notifications.update(ns => ns.map(n => ({ ...n, read_at: n.read_at ?? now })));
      this._unreadCount.set(0);
    });
  }
}
