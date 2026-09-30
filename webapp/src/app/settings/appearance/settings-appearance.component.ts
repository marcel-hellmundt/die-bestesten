import { Component, inject } from '@angular/core';
import { ThemeService } from '../../core/theme.service';

/** /einstellungen/erscheinung — Light/Dark (pro Gerät, siehe ThemeService). */
@Component({
  selector: 'app-settings-appearance',
  standalone: false,
  templateUrl: './settings-appearance.component.html',
  styleUrl: './settings-appearance.component.scss',
})
export class SettingsAppearanceComponent {
  themeSvc = inject(ThemeService);
}
