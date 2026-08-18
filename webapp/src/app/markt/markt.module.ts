import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { MarktComponent } from './markt.component';
import { MarktPlayerComponent } from './player/markt-player.component';
import { TransfersComponent } from './transfers/transfers.component';
import { TransferWindowDetailComponent } from './transfers/transfer-window-detail.component';
import { BidsComponent } from './bids/bids.component';
import { IconModule } from '../shared/icon/icon.module';

const routes: Routes = [
  {
    path: '', component: MarktComponent,
    children: [
      { path: '',                    redirectTo: 'spieler', pathMatch: 'full' },
      { path: 'spieler',             component: MarktPlayerComponent },
      { path: 'transferphasen',      component: TransfersComponent },
      { path: 'transferphasen/:id',  component: TransferWindowDetailComponent },
      { path: 'gebote',              component: BidsComponent },
    ]
  }
];

@NgModule({
  declarations: [MarktComponent, MarktPlayerComponent, TransfersComponent, TransferWindowDetailComponent, BidsComponent],
  imports: [CommonModule, RouterModule.forChild(routes), IconModule]
})
export class MarktModule {}
