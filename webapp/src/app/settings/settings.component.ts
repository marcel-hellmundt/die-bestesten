import { Component } from '@angular/core';

/** Rahmen von /einstellungen mit Pill-Menü: Allgemein (Konto) + Benachrichtigungen. */
@Component({
  selector: 'app-settings',
  standalone: false,
  template: `
    <h1 class="page-title">Einstellungen</h1>

    <nav class="pill-nav pill-nav--sticky pill-nav--last" appStuck>
      <a class="pill" routerLink="allgemein" routerLinkActive="pill--active">Allgemein</a>
      <a class="pill" routerLink="benachrichtigungen" routerLinkActive="pill--active">Benachrichtigungen</a>
    </nav>

    <router-outlet />
  `,
})
export class SettingsComponent {}
