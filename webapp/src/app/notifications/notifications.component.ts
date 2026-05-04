import { Component, inject, signal } from '@angular/core';
import { AppNotification, NotificationService } from '../core/notification.service';

@Component({
  selector: 'app-notifications',
  standalone: false,
  templateUrl: './notifications.component.html',
  styleUrl: './notifications.component.scss'
})
export class NotificationsComponent {
  service  = inject(NotificationService);
  selected = signal<AppNotification | null>(null);

  select(n: AppNotification): void {
    this.selected.set(n);
    if (!n.read_at) this.service.markAsRead(n.id);
  }

  formatDate(dateStr: string, long = false): string {
    const d = new Date(dateStr);
    if (long) {
      return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
        + ' ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    }
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' })
      + ' ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
  }
}
