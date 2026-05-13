import { Component, Injector, TemplateRef, ViewChild, afterNextRender, effect, inject, signal } from '@angular/core';
import { catchError, of } from 'rxjs';
import { ApiService } from '../core/api.service';
import { Achievement } from '../achievements/achievements.component';
import { NotificationService } from '../core/notification.service';
import { DataCacheService } from '../core/data-cache.service';
import { BottomSheetService } from '../core/bottom-sheet.service';

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

  unseenAchievements = signal<Achievement[]>([]);

  @ViewChild('createTeamTpl') createTeamTpl!: TemplateRef<any>;

  constructor() {
    this.api.get<Achievement[]>('achievement').pipe(
      catchError(() => of([] as Achievement[]))
    ).subscribe(achievements => {
      const unseen = achievements.filter(a => a.earned_at && !a.seen_at);
      this.unseenAchievements.set(unseen);
    });
    this.notifService.load();

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
