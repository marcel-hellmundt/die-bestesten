import { Component, computed, inject } from '@angular/core';
import { AuthService } from '../auth/auth.service';
import { StickerStatusService } from '../core/sticker-status.service';

/** Rahmen von /klebrigsten mit Pill-Menü (Sammelalbum, Klebebande, Tauschbörse, Shop, Simulation für Maintainer+). */
@Component({
  selector: 'app-stickers',
  standalone: false,
  templateUrl: './stickers.component.html',
  // Zähler offener Tauschangebote auch auf Desktop zeigen (globales .pill__badge ist nur mobil sichtbar)
  styles: [`.pill__badge--always { display: block; }`],
})
export class StickersComponent {
  private auth = inject(AuthService);
  private status = inject(StickerStatusService);
  isMaintainer = computed(() => this.auth.isMaintainer());
  tradesIncoming = this.status.tradesIncoming;
}
