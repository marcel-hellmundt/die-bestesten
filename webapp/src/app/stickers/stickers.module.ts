import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { MaintainerGuard } from '../auth/maintainer.guard';
import { StickersComponent } from './stickers.component';
import { StickerAlbumComponent } from './sticker-album.component';
import { StickerSimulationComponent } from './sticker-simulation.component';

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
  declarations: [StickersComponent, StickerAlbumComponent, StickerSimulationComponent],
  imports: [CommonModule, RouterModule.forChild(routes)],
})
export class StickersModule {}
