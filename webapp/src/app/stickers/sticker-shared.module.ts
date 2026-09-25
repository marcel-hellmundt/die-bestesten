import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { StickerCardComponent } from './sticker-card/sticker-card.component';
import { StickerCardDialogComponent } from './sticker-card/sticker-card-dialog.component';
import { PackOpenDialogComponent } from './album/pack-open-dialog.component';
import { PackAnnouncementComponent, PackAnnouncementDialogComponent } from './album/pack-announcement.component';

/**
 * "Die Klebrigsten" — Bausteine, die auch außerhalb von /klebrigsten gebraucht werden: Sticker-Karte,
 * große Karte, Pack-Dialog und die globale Ankündigung neuer Packs (in der Shell).
 */
@NgModule({
  declarations: [
    StickerCardComponent, StickerCardDialogComponent, PackOpenDialogComponent,
    PackAnnouncementComponent, PackAnnouncementDialogComponent,
  ],
  imports: [CommonModule],
  exports: [StickerCardComponent, StickerCardDialogComponent, PackOpenDialogComponent, PackAnnouncementComponent],
})
export class StickerSharedModule {}
