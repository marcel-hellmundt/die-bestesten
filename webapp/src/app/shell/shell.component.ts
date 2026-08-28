import { Component, Injector, TemplateRef, ViewChild, afterNextRender, computed, effect, inject, signal } from '@angular/core';
import { Router, NavigationEnd } from '@angular/router';
import { catchError, filter, of } from 'rxjs';
import { ApiService } from '../core/api.service';
import { Achievement } from '../achievements/achievements.component';
import { NotificationService } from '../core/notification.service';
import { DataCacheService } from '../core/data-cache.service';
import { BottomSheetService } from '../core/bottom-sheet.service';
import { PushNotificationService } from '../core/push-notification.service';

// Routes whose content should fill the entire viewport (no page padding/title) instead of
// sitting inside the normal padded content column.
const FULL_BLEED_ROUTES = ['/karte'];

@Component({
  selector: 'app-shell',
  standalone: false,
  templateUrl: './shell.component.html',
  styleUrl: './shell.component.scss'
})
export class ShellComponent {
  private api          = inject(ApiService);
  private notifService = inject(NotificationService);
  private cache        = inject(DataCacheService);
  private bs           = inject(BottomSheetService);
  private pushSvc      = inject(PushNotificationService);
  private router       = inject(Router);

  unseenAchievements = signal<Achievement[]>([]);

  private currentUrl = signal(this.router.url);
  isFullBleed = computed(() => FULL_BLEED_ROUTES.some(r => this.currentUrl().startsWith(r)));

  @ViewChild('createTeamTpl') createTeamTpl!: TemplateRef<any>;
  @ViewChild('pushPromptTpl') pushPromptTpl!: TemplateRef<any>;

  private pushPromptShown = false;

  constructor() {
    this.router.events.pipe(filter(e => e instanceof NavigationEnd)).subscribe((e: any) => {
      this.currentUrl.set(e.urlAfterRedirects);
    });

    this.api.get<Achievement[]>('achievement').pipe(
      catchError(() => of([] as Achievement[]))
    ).subscribe(achievements => {
      const unseen = achievements.filter(a => a.earned_at && !a.seen_at);
      this.unseenAchievements.set(unseen);
    });
    this.notifService.load();
    this.notifService.startPolling();

    this.cache.ensureMyTeam();

    const injector = inject(Injector);
    afterNextRender(() => {
      effect(() => {
        if (this.cache.myTeamLoaded() && !this.cache.myTeam() && !this.bs.isOpen()) {
          this.bs.open(this.createTeamTpl, { title: 'Team erstellen', closeable: false });
        }
      }, { injector });

      // Einmaliger Hinweis-Dialog direkt nach dem Einloggen, der die Push-Berechtigung anbietet
      // (Notification.requestPermission() braucht eine echte Nutzeraktion, siehe
      // PushPromptComponent) — nur wenn der Browser das grundsätzlich unterstützt
      // (isSupported() ist auf iOS bereits false außerhalb einer zum Home-Bildschirm
      // hinzugefügten App), noch keine Entscheidung getroffen wurde (Notification.permission ===
      // 'default') und der Dialog auf diesem Gerät nicht schon einmal weggeklickt wurde. Reagiert
      // auf bs.isOpen(), damit er nach dem (mandatory) Team-erstellen-Dialog automatisch
      // nachrückt, statt in der Warteschlange verloren zu gehen; pushPromptShown verhindert ein
      // erneutes Öffnen, falls bs.isOpen() später aus anderen Gründen noch einmal wechselt.
      effect(() => {
        if (
          !this.bs.isOpen() &&
          !this.pushPromptShown &&
          this.pushSvc.isSupported() &&
          Notification.permission === 'default' &&
          !this.pushSvc.isPromptDismissed()
        ) {
          this.pushPromptShown = true;
          this.bs.open(this.pushPromptTpl, { title: 'Benachrichtigungen', closeable: true });
        }
      }, { injector });
    });
  }
}
