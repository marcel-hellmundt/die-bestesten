import { Component, computed, inject } from '@angular/core';
import { AuthService } from '../auth/auth.service';

/** Rahmen von /klebrigsten mit Pill-Menü (Sammelalbum, Simulation für Maintainer+). */
@Component({
  selector: 'app-stickers',
  standalone: false,
  templateUrl: './stickers.component.html',
})
export class StickersComponent {
  private auth = inject(AuthService);
  isMaintainer = computed(() => this.auth.isMaintainer());
}
