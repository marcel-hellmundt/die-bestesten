import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';

import { DataComponent } from './data.component';
import { CountryDataComponent } from './country/country.component';
import { CountryDetailComponent } from './country/country-detail.component';
import { DivisionDataComponent } from './division/division.component';
import { ClubDataComponent } from './club/club.component';
import { ClubDetailComponent } from './club/club-detail.component';
import { SeasonDataComponent } from './season/season.component';
import { PlayerDataComponent } from './player/player.component';
import { PlayerDetailComponent } from './player/player-detail.component';

const routes: Routes = [
  {
    path: '',
    component: DataComponent,
    children: [
      { path: '', redirectTo: 'player', pathMatch: 'full' },
      { path: 'country',     component: CountryDataComponent },
      { path: 'division',    component: DivisionDataComponent },
      { path: 'country/:id', component: CountryDetailComponent },
      { path: 'club',     component: ClubDataComponent },
      { path: 'club/:id', component: ClubDetailComponent },
      { path: 'season',   component: SeasonDataComponent },
      { path: 'player',      component: PlayerDataComponent },
      { path: 'player/:id', component: PlayerDetailComponent },
    ]
  }
];

@NgModule({
  declarations: [
    DataComponent,
    CountryDataComponent,
    CountryDetailComponent,
    DivisionDataComponent,
    ClubDataComponent,
    ClubDetailComponent,
    SeasonDataComponent,
    PlayerDataComponent,
    PlayerDetailComponent,
  ],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
  ]
})
export class DataModule {}
