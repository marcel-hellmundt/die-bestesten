import { Component, inject } from '@angular/core';
import { NotificationService } from '../../core/notification.service';

/** /einstellungen/benachrichtigungen — welche Ereignisse eine Benachrichtigung auslösen. */
@Component({
  selector: 'app-settings-notifications',
  standalone: false,
  templateUrl: './settings-notifications.component.html',
  styleUrl: './settings-notifications.component.scss',
})
export class SettingsNotificationsComponent {
  private notifSvc = inject(NotificationService);
  preferences = this.notifSvc.preferences;

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
