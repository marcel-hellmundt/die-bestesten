import { Component, computed, inject } from '@angular/core';
import { ThemePreference, ThemeService } from '../../core/theme.service';
import { AuthService } from '../../auth/auth.service';
import { AppearanceService, TOPBAR_ITEMS, TopbarItem, TopbarWhere, topbarDefault } from '../../core/appearance.service';
import { StickerStatusService } from '../../core/sticker-status.service';

interface ThemeOption {
  value: ThemePreference;
  icon: string;   // app-icon (shared/icon)
  label: string;
}

const OPTIONS: ThemeOption[] = [
  { value: 'light',  icon: 'sun',     label: 'Hell' },
  { value: 'dark',   icon: 'moon',    label: 'Dunkel' },
  { value: 'system', icon: 'monitor', label: 'System' },
];

/** Einträge rechts in der Topbar: Name und Symbol (app-icon) für die Einstellungs-Karte */
const TOPBAR_ROWS: { item: TopbarItem; icon: string | null; name: string }[] = [
  { item: 'lukaten',            icon: null,      name: 'Lukaten' },
  { item: 'karte',              icon: 'map',     name: 'Karte' },
  { item: 'klebrigsten',        icon: 'sticker', name: 'Die Klebrigsten' },
  { item: 'achievements',       icon: 'award',   name: 'Achievements' },
  { item: 'benachrichtigungen', icon: 'bell',    name: 'Benachrichtigungen' },
];

/**
 * /einstellungen/erscheinung — Hell/Dunkel/System (pro Gerät, siehe ThemeService) und darunter, welche Einträge
 * rechts in der Topbar stehen (je Nutzer, getrennt für Desktop und Handy; gespeichert am Konto, siehe
 * AppearanceService — Abgewähltes liegt im Benutzermenü).
 */
@Component({
  selector: 'app-settings-appearance',
  standalone: false,
  templateUrl: './settings-appearance.component.html',
  styleUrl: './settings-appearance.component.scss',
})
export class SettingsAppearanceComponent {
  themeSvc = inject(ThemeService);
  options = OPTIONS;

  private appearance = inject(AppearanceService);
  private auth = inject(AuthService);
  private stickers = inject(StickerStatusService);

  /** Klebrigsten nur für Manager mit Album (wie in der Topbar) */
  topbarRows = computed(() => TOPBAR_ROWS.filter(r =>
    r.item !== 'klebrigsten' || this.stickers.enabled() || this.auth.isMaintainer()));
  /** weicht irgendetwas vom Standard ab? → "Auf Standard zurücksetzen" anbieten */
  topbarChanged = computed(() => TOPBAR_ITEMS.some(item =>
    (['desktop', 'mobile'] as TopbarWhere[]).some(w => this.appearance.topbarPref(item, w) !== topbarDefault(item, w))));

  constructor() {
    this.appearance.load();
  }

  shows(item: TopbarItem, where: TopbarWhere): boolean {
    return this.appearance.topbarShows(item, where);
  }

  setTopbar(item: TopbarItem, where: TopbarWhere, enabled: boolean): void {
    // Handy: nur ein Eintrag hat Platz — ein neu gewählter löst den bisherigen ab
    if (where === 'mobile' && enabled) {
      for (const other of TOPBAR_ITEMS) {
        if (other !== item && this.appearance.topbarPref(other, 'mobile')) this.appearance.setTopbar(other, 'mobile', false);
      }
    }
    this.appearance.setTopbar(item, where, enabled);
  }

  resetTopbar(): void {
    for (const item of TOPBAR_ITEMS) {
      for (const where of ['desktop', 'mobile'] as TopbarWhere[]) {
        const standard = topbarDefault(item, where);
        if (this.appearance.topbarPref(item, where) !== standard) this.appearance.setTopbar(item, where, standard);
      }
    }
  }
}
