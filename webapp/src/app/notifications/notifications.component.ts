import { Component, OnInit, inject, signal } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { AppNotification, NotificationService } from '../core/notification.service';
import { MatchdaySummaryService } from '../core/matchday-summary.service';

@Component({
  selector: 'app-notifications',
  standalone: false,
  templateUrl: './notifications.component.html',
  styleUrl: './notifications.component.scss'
})
export class NotificationsComponent implements OnInit {
  service   = inject(NotificationService);
  private sanitizer = inject(DomSanitizer);
  private summary   = inject(MatchdaySummaryService);
  selected  = signal<AppNotification | null>(null);
  /** Spieltags-Zusammenfassung wird gerade geholt bzw. ließ sich nicht öffnen */
  summaryLoading = signal(false);
  summaryError   = signal(false);

  ngOnInit(): void {
    this.service.reload();
  }

  renderMessage(msg: string | null): SafeHtml {
    if (!msg) return '';
    if (/<[a-z][\s\S]*>/i.test(msg)) {
      return this.sanitizer.bypassSecurityTrustHtml(msg);
    }
    const escaped = msg.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return this.sanitizer.bypassSecurityTrustHtml(escaped.replace(/\n/g, '<br>'));
  }

  // Mobile: zurück von der Detailansicht zur Liste (auf Desktop steht die Liste ohnehin daneben).
  back(): void {
    this.selected.set(null);
  }

  select(n: AppNotification): void {
    this.selected.set(n);
    this.summaryError.set(false);
    if (!n.read_at) this.service.markAsRead(n.id);
  }

  /** Die Zusammenfassung zum Spieltag noch einmal groß einblenden (wie direkt nach dem Abschluss). */
  openSummary(n: AppNotification): void {
    if (!n.matchday_summary_id || this.summaryLoading()) return;
    this.summaryLoading.set(true);
    this.summaryError.set(false);
    this.summary.open(n.matchday_summary_id).subscribe({
      next: () => this.summaryLoading.set(false),
      error: () => {
        this.summaryLoading.set(false);
        this.summaryError.set(true);
      },
    });
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
