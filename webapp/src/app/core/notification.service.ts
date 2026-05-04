import { Injectable, computed, inject, signal } from '@angular/core';
import { catchError, of } from 'rxjs';
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

@Injectable({ providedIn: 'root' })
export class NotificationService {
  private api    = inject(ApiService);
  private loaded = false;

  private _notifications = signal<AppNotification[]>([]);
  notifications = this._notifications.asReadonly();
  unreadCount   = computed(() => this._notifications().filter(n => !n.read_at).length);

  load(): void {
    if (this.loaded) return;
    this.loaded = true;
    this.api.get<AppNotification[]>('notification').pipe(
      catchError(() => of([] as AppNotification[]))
    ).subscribe(ns => this._notifications.set(ns));
  }

  markAsRead(id: string): void {
    this.api.patch<any>(`notification/${id}`, {}).subscribe(() =>
      this._notifications.update(ns =>
        ns.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n)
      )
    );
  }

  markAllAsRead(): void {
    this.api.patch<any>('notification/read_all', {}).subscribe(() => {
      const now = new Date().toISOString();
      this._notifications.update(ns => ns.map(n => ({ ...n, read_at: n.read_at ?? now })));
    });
  }
}
