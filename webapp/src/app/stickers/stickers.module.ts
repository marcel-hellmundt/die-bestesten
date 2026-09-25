import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { MaintainerGuard } from '../auth/maintainer.guard';
import { StickersComponent } from './stickers.component';
import { StickerAlbumComponent } from './album/sticker-album.component';
import { AlbumOverviewComponent } from './album/album-overview.component';
import { AlbumClubPageComponent } from './album/album-club-page.component';
import { StickerSimulationComponent } from './sticker-simulation.component';
import { StickerSharedModule } from './sticker-shared.module';

// "Die Klebrigsten" (Sticker-Album): /klebrigsten/sammelalbum + /klebrigsten/simulation (Maintainer+)
const routes: Routes = [
  {
    path: '', component: StickersComponent,
    children: [
      { path: '',            redirectTo: 'sammelalbum', pathMatch: 'full' },
      { path: 'sammelalbum', component: StickerAlbumComponent },
      { path: 'simulation',  component: StickerSimulationComponent, canActivate: [MaintainerGuard] },
    ],
  },
];

@NgModule({
  declarations: [
    StickersComponent, StickerAlbumComponent, AlbumOverviewComponent, AlbumClubPageComponent,
    StickerSimulationComponent,
  ],
  imports: [CommonModule, RouterModule.forChild(routes), StickerSharedModule],
})
export class StickersModule {}
