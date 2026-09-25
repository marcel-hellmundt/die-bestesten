import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { MaintainerGuard } from '../auth/maintainer.guard';
import { StickersComponent } from './stickers.component';
import { StickerAlbumComponent } from './album/sticker-album.component';
import { AlbumOverviewComponent } from './album/album-overview.component';
import { AlbumClubPageComponent } from './album/album-club-page.component';
import { StickerSimulationComponent } from './sticker-simulation.component';
import { StickerCardComponent } from './sticker-card/sticker-card.component';
import { StickerCardDialogComponent } from './sticker-card/sticker-card-dialog.component';

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
    StickerSimulationComponent, StickerCardComponent, StickerCardDialogComponent,
  ],
  imports: [CommonModule, RouterModule.forChild(routes)],
})
export class StickersModule {}
