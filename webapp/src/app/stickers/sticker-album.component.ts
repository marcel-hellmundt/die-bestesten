import { Component } from '@angular/core';

/** Persönliches Sammelalbum — noch nicht umgesetzt (V0 = nur Simulation). */
@Component({
  selector: 'app-sticker-album',
  standalone: false,
  template: `
    <div class="album-page">
      <h1 class="page-title">Sammelalbum</h1>
      <p class="state-msg">Das Sammelalbum kommt bald.</p>
    </div>
  `,
  styles: [`.album-page { padding: 16px; }`],
})
export class StickerAlbumComponent {}
