import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { ApiService } from './api.service';
import { environment } from '../../environments/environment';

export type PushStatus = 'unsupported' | 'denied' | 'subscribed' | 'not-subscribed';

@Injectable({ providedIn: 'root' })
export class PushNotificationService {
  private api = inject(ApiService);

  private _status = signal<PushStatus>('not-subscribed');
  status = this._status.asReadonly();

  private _busy = signal(false);
  busy = this._busy.asReadonly();

  private _error = signal<string | null>(null);
  error = this._error.asReadonly();

  isSupported(): boolean {
    return 'serviceWorker' in navigator && 'PushManager' in window && typeof Notification !== 'undefined';
  }

  // iOS/iPadOS Safari erlaubt eine Push-Berechtigungsanfrage nur, wenn die Seite als
  // eigenständige App vom Home-Bildschirm läuft (seit iOS 16.4) — ein normaler Safari-Tab kann
  // gar nicht erst fragen, siehe manifest.json (nötig für "Zum Home-Bildschirm").
  isStandalone(): boolean {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      (navigator as any).standalone === true
    );
  }

  isIos(): boolean {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !(window as any).MSStream;
  }

  // Merkt sich pro Gerät/Browser (nicht pro Manager, da rein lokal), ob der einmalige
  // Push-Hinweis-Dialog (siehe shell/push-prompt) bereits weggeklickt wurde — verhindert, dass er
  // bei jedem Login erneut nervt, unabhängig davon ob der Nutzer sich für/gegen Push entschieden hat.
  private readonly promptDismissedKey = 'push_prompt_dismissed';

  isPromptDismissed(): boolean {
    try {
      return localStorage.getItem(this.promptDismissedKey) === '1';
    } catch {
      return false;
    }
  }

  dismissPrompt(): void {
    try {
      localStorage.setItem(this.promptDismissedKey, '1');
    } catch {
      // localStorage kann in seltenen Fällen blockiert sein (privater Modus etc.) — dann bleibt
      // der Dialog beim nächsten Login halt erneut sichtbar, kein harter Fehler nötig.
    }
  }

  async refreshStatus(): Promise<void> {
    if (!this.isSupported()) {
      this._status.set('unsupported');
      return;
    }
    if (Notification.permission === 'denied') {
      this._status.set('denied');
      return;
    }
    try {
      const registration = await navigator.serviceWorker.getRegistration('sw.js');
      const subscription = await registration?.pushManager.getSubscription();
      this._status.set(subscription ? 'subscribed' : 'not-subscribed');
    } catch {
      this._status.set('not-subscribed');
    }
  }

  async subscribe(): Promise<void> {
    if (!this.isSupported()) {
      this._error.set('Push-Benachrichtigungen werden von diesem Browser nicht unterstützt.');
      return;
    }
    this._busy.set(true);
    this._error.set(null);
    try {
      const registration = await navigator.serviceWorker.register('sw.js');
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        this._status.set(permission === 'denied' ? 'denied' : 'not-subscribed');
        return;
      }

      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: this.urlBase64ToUint8Array(environment.vapidPublicKey),
      });
      const json = subscription.toJSON();
      await firstValueFrom(this.api.post('push_subscription', { endpoint: json.endpoint, keys: json.keys }));
      this._status.set('subscribed');
    } catch {
      this._error.set('Push-Benachrichtigungen konnten nicht aktiviert werden.');
    } finally {
      this._busy.set(false);
    }
  }

  async unsubscribe(): Promise<void> {
    this._busy.set(true);
    this._error.set(null);
    try {
      const registration = await navigator.serviceWorker.getRegistration('sw.js');
      const subscription = await registration?.pushManager.getSubscription();
      if (subscription) {
        const endpoint = subscription.endpoint;
        await subscription.unsubscribe();
        await firstValueFrom(this.api.delete('push_subscription', { endpoint }));
      }
      this._status.set('not-subscribed');
    } catch {
      this._error.set('Push-Benachrichtigungen konnten nicht deaktiviert werden.');
    } finally {
      this._busy.set(false);
    }
  }

  private urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const bytes = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) bytes[i] = rawData.charCodeAt(i);
    return bytes;
  }
}
