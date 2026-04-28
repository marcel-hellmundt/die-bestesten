import { Component, EventEmitter, inject, Input, Output } from '@angular/core';
import { Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { Achievement } from '../../achievements/achievements.component';

@Component({
  selector: 'app-achievement-notification',
  standalone: false,
  templateUrl: './achievement-notification.component.html',
  styleUrl: './achievement-notification.component.scss',
})
export class AchievementNotificationComponent {
  @Input() achievements: Achievement[] = [];
  @Output() dismissed = new EventEmitter<void>();

  private api    = inject(ApiService);
  private router = inject(Router);

  get visible()   { return this.achievements.length > 0; }
  get iconStack() { return this.achievements.slice(0, 3); }
  get count()     { return this.achievements.length; }

  onView(): void {
    this.markSeen();
    this.router.navigate(['/achievements']);
    this.dismissed.emit();
  }

  onSkip(): void {
    this.markSeen();
    this.dismissed.emit();
  }

  private markSeen(): void {
    this.api.patch('achievement/seen', {}).subscribe();
  }
}
