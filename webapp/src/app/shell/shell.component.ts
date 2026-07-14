import { Component, Injector, TemplateRef, ViewChild, afterNextRender, computed, effect, inject, signal } from '@angular/core';
import { Router, NavigationEnd } from '@angular/router';
import { catchError, filter, of } from 'rxjs';
import { ApiService } from '../core/api.service';
import { Achievement } from '../achievements/achievements.component';
import { NotificationService } from '../core/notification.service';
import { DataCacheService } from '../core/data-cache.service';
import { BottomSheetService } from '../core/bottom-sheet.service';

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

  unseenAchievements = signal<Achievement[]>([]);

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
    this.notifService.startPolling();

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
