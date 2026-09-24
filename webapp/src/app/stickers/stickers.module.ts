import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { StickerSimulationComponent } from './sticker-simulation.component';

// "Die Klebrigsten" (Sticker-Album) — V0: nur die Parameter-Simulation.
const routes: Routes = [
  { path: '', component: StickerSimulationComponent },
];

@NgModule({
  declarations: [StickerSimulationComponent],
  imports: [CommonModule, RouterModule.forChild(routes)],
})
export class StickersModule {}
