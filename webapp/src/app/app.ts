import { Component, inject } from '@angular/core';
import { NavigationEnd, NavigationError, Router } from '@angular/router';

@Component({
  selector: 'app-root',
  templateUrl: './app.html',
  standalone: false,
  styleUrl: './app.scss'
})
export class App {
  // Nach jedem Deploy löscht die FTP-Sync (mirror --reverse --delete, siehe
  // deploy-webapp.yml) alte, gehashte Chunk-Dateien vom Server. Ein bereits offener Tab hat
  // sein main.js schon geladen und versucht beim nächsten Lazy-Route-Wechsel trotzdem noch,
  // einen mittlerweile gelöschten Chunk zu laden — die SPA-Fallback-Regel in public/.htaccess
  // liefert dafür index.html statt JS zurück ("Failed to fetch dynamically imported module").
  // Einmaliger Reload lädt das aktuelle main.js neu (index.html ist bewusst no-cache, siehe
  // .htaccess); der sessionStorage-Guard verhindert eine Reload-Schleife, falls der Fehler aus
  // einem anderen Grund (z.B. echter Netzwerkausfall) bestehen bleibt, wird aber nach jeder
  // erfolgreichen Navigation zurückgesetzt, damit ein späterer Deploy wieder erkannt wird.
  private static readonly RELOAD_GUARD_KEY = 'chunk-reload-attempted';

  constructor() {
    const router = inject(Router);
    router.events.subscribe(event => {
      if (event instanceof NavigationEnd) {
        sessionStorage.removeItem(App.RELOAD_GUARD_KEY);
        return;
      }
      if (!(event instanceof NavigationError)) return;

      const message = event.error?.message ?? '';
      if (!/dynamically imported module|module script failed/i.test(message)) return;
      if (sessionStorage.getItem(App.RELOAD_GUARD_KEY)) return;

      sessionStorage.setItem(App.RELOAD_GUARD_KEY, '1');
      window.location.reload();
    });
  }
}
