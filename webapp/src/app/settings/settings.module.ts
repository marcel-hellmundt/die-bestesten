import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { StuckDirective } from '../core/stuck.directive';
import { IconModule } from '../shared/icon/icon.module';
import { SettingsComponent } from './settings.component';
import { SettingsGeneralComponent } from './general/settings-general.component';
import { SettingsNotificationsComponent } from './notifications/settings-notifications.component';
import { SettingsAppearanceComponent } from './appearance/settings-appearance.component';

// /einstellungen/allgemein (Konto, E-Mail, Passwort, Konto löschen) + /einstellungen/benachrichtigungen
// + /einstellungen/erscheinung (Light/Dark)
const routes: Routes = [
  {
    path: '', component: SettingsComponent,
    children: [
      { path: '',                   redirectTo: 'allgemein', pathMatch: 'full' },
      { path: 'allgemein',          component: SettingsGeneralComponent },
      { path: 'benachrichtigungen', component: SettingsNotificationsComponent },
      { path: 'erscheinung',        component: SettingsAppearanceComponent },
    ],
  },
];

@NgModule({
  declarations: [SettingsComponent, SettingsGeneralComponent, SettingsNotificationsComponent, SettingsAppearanceComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
    StuckDirective,
    IconModule,
  ]
})
export class SettingsModule {}
