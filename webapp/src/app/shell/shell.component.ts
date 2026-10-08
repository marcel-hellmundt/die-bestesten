import { Component, Injector, TemplateRef, ViewChild, afterNextRender, computed, effect, inject, signal } from '@angular/core';
import { Router, NavigationEnd } from '@angular/router';
import { catchError, filter, of } from 'rxjs';
import { ApiService } from '../core/api.service';
import { Achievement } from '../achievements/achievements.component';
import { NotificationService } from '../core/notification.service';
import { DataCacheService } from '../core/data-cache.service';
import { BottomSheetService } from '../core/bottom-sheet.service';
import { MatchdaySummaryService } from '../core/matchday-summary.service';

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
  private router       = inject(Router);
  private summary      = inject(MatchdaySummaryService);

  unseenAchievements = signal<Achievement[]>([]);
  /** Einblendung neuer Achievements — abschaltbar unter Einstellungen → Benachrichtigungen → Einblendungen */
  // Nach einem Spieltagsabschluss kommt erst die Zusammenfassung (sie nennt die neuen Achievements)
  overlayAchievements = computed(() =>
    this.notifService.overlayAllowed('overlay_achievement') && !this.summary.blocking() ? this.unseenAchievements() : []
  );

  private currentUrl = signal(this.router.url);
  isFullBleed = computed(() => FULL_BLEED_ROUTES.some(r => this.currentUrl().startsWith(r)));

  @ViewChild('createTeamTpl') createTeamTpl!: TemplateRef<any>;

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
    this.notifService.loadPreferences(); // steuert u.a. die Einblendungen (Achievements, Sticker-Packs)
    this.notifService.startPolling();
    this.summary.start(); // Spieltags-Zusammenfassung nach einem Abschluss

    this.cache.ensureMyTeam();

    const injector = inject(Injector);
    afterNextRender(() => {
      effect(() => {
        if (this.cache.myTeamLoaded() && !this.cache.myTeam() && !this.bs.isOpen()) {
          this.bs.open(this.createTeamTpl, { title: 'Team erstellen', closeable: false });
        }
      }, { injector });
    });
  }
}
