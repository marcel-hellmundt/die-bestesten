import { Component, EventEmitter, inject, Input, Output } from '@angular/core';
import { Router } from '@angular/router';
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

  private router = inject(Router);

  get visible()    { return this.achievements.length > 0; }
  get iconStack()  { return this.achievements.slice(0, 3); }
  get iconCount()  { return Math.min(this.achievements.length, 3); }
  get count()      { return this.achievements.length; }

  onView(): void {
    this.router.navigate(['/achievements']);
    this.dismissed.emit();
  }

  onSkip(): void {
    this.dismissed.emit();
  }
}
