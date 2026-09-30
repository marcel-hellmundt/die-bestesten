import { Component, inject } from '@angular/core';
import { ThemePreference, ThemeService } from '../../core/theme.service';

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

/** /einstellungen/erscheinung — Hell/Dunkel/System (pro Gerät, siehe ThemeService). */
@Component({
  selector: 'app-settings-appearance',
  standalone: false,
  templateUrl: './settings-appearance.component.html',
  styleUrl: './settings-appearance.component.scss',
})
export class SettingsAppearanceComponent {
  themeSvc = inject(ThemeService);
  options = OPTIONS;
}
