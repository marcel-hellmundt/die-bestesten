import { Component, effect, inject, input, signal, untracked } from '@angular/core';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { PackCard, PackInfo } from '../album/pack.model';
import { PackOpener } from '../album/pack-opener';

/**
 * Eine Bühne der Pack-Testseite: die übergebenen Packs nebeneinander im Pack-Dialog (inline), aufreißbar,
 * danach wieder alle. Eigener PackOpener je Bühne; StickerAlbumService kommt von der Testseite.
 */
@Component({
  selector: 'app-pack-stage',
  standalone: false,
  templateUrl: './pack-stage.component.html',
  providers: [PackOpener],
})
export class PackStageComponent {
  title = input('');
  packs = input<PackInfo[]>([]);

  opener = inject(PackOpener);
  openCard = signal<StickerCardData | null>(null);

  constructor() {
    effect(() => {
      const packs = this.packs();
      untracked(() => this.opener.showChoice(packs));
    });
  }

  /** nach "Fertig", "Nächstes Pack" oder "Später öffnen" wieder alle Packs zeigen */
  reset(): void {
    this.opener.showChoice(this.packs());
  }

  pick(index: number): void {
    this.opener.pick(index);
  }

  openPulled(c: PackCard): void {
    this.openCard.set(c.card);
  }
}
