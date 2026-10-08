import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { StickerSharedModule } from '../sticker-shared.module';
import { PackGalleryComponent } from './pack-gallery.component';
import { PackStageComponent } from './pack-stage.component';

/**
 * Pack-Testseite (alle Pack-Designs zum Aufreißen, nur im Browser gewürfelt) als eigener Baustein — eingebunden
 * bei den Test-Oberflächen der Verwaltung (/verwaltung/ui-tests), nicht mehr unter /klebrigsten.
 */
@NgModule({
  declarations: [PackGalleryComponent, PackStageComponent],
  imports: [CommonModule, StickerSharedModule],
  exports: [PackGalleryComponent],
})
export class PackGalleryModule {}
