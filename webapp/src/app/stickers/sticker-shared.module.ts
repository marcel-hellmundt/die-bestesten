import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { StickerCardComponent } from './sticker-card/sticker-card.component';
import { StickerCardDialogComponent } from './sticker-card/sticker-card-dialog.component';
import { PackOpenDialogComponent } from './album/pack-open-dialog.component';
import { PackCoverComponent } from './album/pack-cover.component';
import { PackAnnouncementComponent, PackAnnouncementDialogComponent } from './album/pack-announcement.component';

/**
 * "Die Klebrigsten" — Bausteine, die auch außerhalb von /klebrigsten gebraucht werden: Sticker-Karte,
 * große Karte, Pack-Dialog, die kleine Pack-Ansicht (app-pack-cover, z.B. in der Spieltags-Zusammenfassung) und
 * die globale Ankündigung neuer Packs (in der Shell).
 */
@NgModule({
  declarations: [
    StickerCardComponent, StickerCardDialogComponent, PackOpenDialogComponent, PackCoverComponent,
    PackAnnouncementComponent, PackAnnouncementDialogComponent,
  ],
  imports: [CommonModule],
  exports: [StickerCardComponent, StickerCardDialogComponent, PackOpenDialogComponent, PackCoverComponent, PackAnnouncementComponent],
})
export class StickerSharedModule {}
