import { Component, inject } from '@angular/core';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { PushNotificationService } from '../../core/push-notification.service';

@Component({
  selector: 'app-push-prompt',
  standalone: false,
  templateUrl: './push-prompt.component.html',
  styleUrl: './push-prompt.component.scss',
})
export class PushPromptComponent {
  private bs = inject(BottomSheetService);
  pushSvc = inject(PushNotificationService);

  async enable(): Promise<void> {
    await this.pushSvc.subscribe();
    if (this.pushSvc.status() === 'subscribed') {
      this.bs.close();
    }
  }

  dismiss(): void {
    this.pushSvc.dismissPrompt();
    this.bs.close();
  }
}
