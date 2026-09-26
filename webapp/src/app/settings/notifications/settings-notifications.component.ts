import { Component, computed, inject } from '@angular/core';
import { NotificationService } from '../../core/notification.service';
import { StickerStatusService } from '../../core/sticker-status.service';
import { AuthService } from '../../auth/auth.service';

interface PrefRow {
  key: string;      // event_type in /notification/preferences
  icon: string;     // app-icon (shared/icon)
  name: string;
  desc: string;
  sticker?: true;   // nur mit "Die Klebrigsten"
}

const NOTIFICATION_ROWS: PrefRow[] = [
  { key: 'matchday_completed', icon: 'spieltag',  name: 'Spieltag abgeschlossen', desc: 'Wenn ein Spieltag ausgewertet wurde' },
  { key: 'achievement_earned', icon: 'award',     name: 'Achievement erhalten',   desc: 'Wenn du ein neues Achievement freischaltest' },
  { key: 'h2h_draw',           icon: 'shuffle',   name: 'H2H-Auslosung',          desc: 'Bei Auslosung der Gruppenphase, des Viertelfinales oder Halbfinales' },
  { key: 'direct_offer',       icon: 'handshake', name: 'Direktangebote',         desc: 'Wenn jemand ein Angebot für einen deiner Spieler abgibt oder auf dein Angebot antwortet' },
  { key: 'sticker_pack',       icon: 'sticker',   name: 'Sticker-Pack erhalten',  desc: 'Zähler für ungeöffnete Packs oben in der Topbar', sticker: true },
  { key: 'sticker_trade',      icon: 'handshake', name: 'Sticker-Tausch',         desc: 'Wenn dir jemand einen Tausch anbietet oder auf dein Angebot antwortet', sticker: true },
];

const OVERLAY_ROWS: PrefRow[] = [
  { key: 'overlay_achievement', icon: 'award',   name: 'Neues Achievement',   desc: 'Sobald du ein Achievement freigeschaltet hast — sonst nur unter Achievements' },
  { key: 'overlay_pack',        icon: 'sticker', name: 'Neues Sticker-Pack',  desc: 'Neue Packs direkt zum Aufreißen anzeigen — sonst nur im Sammelalbum', sticker: true },
];

/** /einstellungen/benachrichtigungen — Benachrichtigungen + Einblendungen (je ein Schalter pro Ereignis). */
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

  /** Sticker-Schalter nur für Manager mit Album ("Die Klebrigsten" in einer ihrer Ligen) */
  private hasStickers = computed(() => this.stickerStatus.enabled() || this.auth.isMaintainer());
  notificationRows = computed(() => NOTIFICATION_ROWS.filter(r => !r.sticker || this.hasStickers()));
  overlayRows = computed(() => OVERLAY_ROWS.filter(r => !r.sticker || this.hasStickers()));

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
