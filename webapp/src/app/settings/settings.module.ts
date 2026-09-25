import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { StuckDirective } from '../core/stuck.directive';
import { IconModule } from '../shared/icon/icon.module';
import { SettingsComponent } from './settings.component';
import { SettingsGeneralComponent } from './general/settings-general.component';
import { SettingsNotificationsComponent } from './notifications/settings-notifications.component';

// /einstellungen/allgemein (Konto, E-Mail, Passwort, Konto löschen) + /einstellungen/benachrichtigungen
const routes: Routes = [
  {
    path: '', component: SettingsComponent,
    children: [
      { path: '',                   redirectTo: 'allgemein', pathMatch: 'full' },
      { path: 'allgemein',          component: SettingsGeneralComponent },
      { path: 'benachrichtigungen', component: SettingsNotificationsComponent },
    ],
  },
];

@NgModule({
  declarations: [SettingsComponent, SettingsGeneralComponent, SettingsNotificationsComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
    StuckDirective,
    IconModule,
  ]
})
export class SettingsModule {}
