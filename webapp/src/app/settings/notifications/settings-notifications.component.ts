import { Component, computed, inject } from '@angular/core';
import { NotificationService } from '../../core/notification.service';
import { StickerStatusService } from '../../core/sticker-status.service';
import { AuthService } from '../../auth/auth.service';

/** /einstellungen/benachrichtigungen — welche Ereignisse eine Benachrichtigung auslösen. */
@Component({
  selector: 'app-settings-notifications',
  standalone: false,
  templateUrl: './settings-notifications.component.html',
  styleUrl: './settings-notifications.component.scss',
})
export class SettingsNotificationsComponent {
  private notifSvc = inject(NotificationService);
  private auth = inject(AuthService);
  private stickerStatus = inject(StickerStatusService);
  preferences = this.notifSvc.preferences;

  /** Sticker-Pack-Einblendung nur für Manager mit Album ("Die Klebrigsten" in einer ihrer Ligen) */
  showPackOverlay = computed(() => this.stickerStatus.enabled() || this.auth.isMaintainer());

  constructor() {
    this.notifSvc.loadPreferences();
  }

  pref(key: string): boolean {
    const val = this.preferences()[key];
    return val === undefined ? true : val;
  }

  setPreference(eventType: string, enabled: boolean): void {
    this.notifSvc.setPreference(eventType, enabled);
  }
}
