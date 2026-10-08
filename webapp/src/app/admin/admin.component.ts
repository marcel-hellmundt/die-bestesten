import { Component } from '@angular/core';

/** Rahmen von /verwaltung (nur Admin) mit Pill-Menü: Ligen, Manager, Achievements, Lukaten, Nutzung, UI-Tests. */
@Component({
  selector: 'app-admin',
  standalone: false,
  template: `
    <h1 class="page-title">Verwaltung</h1>
    <p class="page-subtitle">Ligen, Manager, Achievements, Lukaten, Nutzung und UI-Tests</p>

    <nav class="pill-nav pill-nav--sticky pill-nav--last" appStuck>
      <a class="pill" routerLink="ligen" routerLinkActive="pill--active">Ligen</a>
      <a class="pill" routerLink="manager" routerLinkActive="pill--active">Manager</a>
      <a class="pill" routerLink="achievements" routerLinkActive="pill--active">Achievements</a>
      <a class="pill" routerLink="lukaten" routerLinkActive="pill--active">Lukaten</a>
      <a class="pill" routerLink="nutzung" routerLinkActive="pill--active">Nutzung</a>
      <a class="pill" routerLink="ui-tests" routerLinkActive="pill--active">UI-Tests</a>
    </nav>

    <router-outlet />
  `,
})
export class AdminComponent {}
