import { Component, inject, signal } from '@angular/core';
import { catchError, of } from 'rxjs';
import { ApiService } from '../core/api.service';
import { Achievement } from '../achievements/achievements.component';
import { NotificationService } from '../core/notification.service';

@Component({
  selector: 'app-shell',
  standalone: false,
  templateUrl: './shell.component.html',
  styleUrl: './shell.component.scss'
})
export class ShellComponent {
  private api         = inject(ApiService);
  private notifService = inject(NotificationService);

  unseenAchievements = signal<Achievement[]>([]);

  constructor() {
    this.api.get<Achievement[]>('achievement').pipe(
      catchError(() => of([] as Achievement[]))
    ).subscribe(achievements => {
      const unseen = achievements.filter(a => a.earned_at && !a.seen_at);
      this.unseenAchievements.set(unseen);
    });
    this.notifService.load();
  }
}
