import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { MarktPlayerComponent } from './player/markt-player.component';
import { TransfersComponent } from './transfers/transfers.component';
import { BidsComponent } from './bids/bids.component';

const routes: Routes = [
  { path: '', redirectTo: 'spieler', pathMatch: 'full' },
  { path: 'spieler',        component: MarktPlayerComponent },
  { path: 'transferphasen', component: TransfersComponent },
  { path: 'gebote',         component: BidsComponent },
];

@NgModule({
  declarations: [MarktPlayerComponent, TransfersComponent, BidsComponent],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class MarktModule {}
