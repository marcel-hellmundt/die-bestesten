import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { MaintainerGuard } from '../auth/maintainer.guard';
import { StuckDirective } from '../core/stuck.directive';
import { StickersComponent } from './stickers.component';
import { StickerAlbumComponent } from './album/sticker-album.component';
import { AlbumOverviewComponent } from './album/album-overview.component';
import { AlbumClubPageComponent } from './album/album-club-page.component';
import { StickerSimulationComponent } from './sticker-simulation.component';
import { StickerSharedModule } from './sticker-shared.module';
import { StickerCollectorsComponent } from './collectors/sticker-collectors.component';
import { StickerShopComponent } from './shop/sticker-shop.component';

// "Die Klebrigsten" (Sticker-Album): /klebrigsten/sammelalbum (eigenes Album), /klebrigsten/klebebande
// (alle Sammler, Klick → deren Album), /klebrigsten/shop (Lukaten → Packs) + /klebrigsten/simulation (Maintainer+)
const routes: Routes = [
  {
    path: '', component: StickersComponent,
    children: [
      { path: '',            redirectTo: 'sammelalbum', pathMatch: 'full' },
      { path: 'sammelalbum', component: StickerAlbumComponent },
      { path: 'klebebande',  component: StickerCollectorsComponent },
      // Album eines anderen Managers (nur ansehen) — dieselbe Album-Komponente, Manager aus dem Pfad
      { path: 'klebebande/:managerId', component: StickerAlbumComponent },
      { path: 'shop',        component: StickerShopComponent },
      { path: 'simulation',  component: StickerSimulationComponent, canActivate: [MaintainerGuard] },
    ],
  },
];

@NgModule({
  declarations: [
    StickersComponent, StickerAlbumComponent, AlbumOverviewComponent, AlbumClubPageComponent,
    StickerSimulationComponent, StickerCollectorsComponent, StickerShopComponent,
  ],
  imports: [CommonModule, RouterModule.forChild(routes), StickerSharedModule, StuckDirective],
})
export class StickersModule {}
