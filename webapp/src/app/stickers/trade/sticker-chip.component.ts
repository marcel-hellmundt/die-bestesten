import { Component, computed, inject, input } from '@angular/core';
import { StickerAlbumService } from '../album/sticker-album.service';
import { POSITION_LABEL, Sticker, TIER_LABEL } from '../album/album.model';

/**
 * Kleine Sticker-Kachel für Tauschangebote: Bild (Spielerfoto/Wappen/Stadion), Name, Verein,
 * Rand in Seltenheitsfarbe. Braucht einen StickerAlbumService im Injector der Seite.
 */
@Component({
  selector: 'app-sticker-chip',
  standalone: false,
  template: `
    @let s = sticker();
    <span class="chip chip--{{ s.tier }}" [class.chip--selected]="selected()" [title]="tierLabel[s.tier]">
      <span class="chip__img">
        @if (image(); as url) { <img [src]="url" alt="" loading="lazy" /> }
        @else { <img class="chip__placeholder" src="img/placeholders/player.png" alt="" /> }
      </span>
      <span class="chip__text">
        <span class="chip__name">{{ s.displayname }}</span>
        <span class="chip__meta">
          <img class="chip__logo" [src]="clubLogo()" alt="" />
          {{ s.kind === 'logo' ? 'Wappen' : s.kind === 'stadium' ? 'Stadion' : (s.position ? positionLabel[s.position] : '') }}
        </span>
      </span>
      @if (selected()) { <span class="chip__check" aria-hidden="true">✓</span> }
    </span>
  `,
  styleUrl: './sticker-chip.component.scss',
})
export class StickerChipComponent {
  private album = inject(StickerAlbumService);

  sticker = input.required<Sticker>();
  selected = input(false);

  image = computed(() => this.album.stickerImageUrl(this.sticker()));
  clubLogo = computed(() => this.album.clubLogoUrl(this.album.clubs()[this.sticker().clubIdx]));

  readonly tierLabel = TIER_LABEL;
  readonly positionLabel = POSITION_LABEL;
}
