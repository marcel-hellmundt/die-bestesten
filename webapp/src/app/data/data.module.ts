import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';

import { DataComponent } from './data.component';
import { CountryDataComponent } from './country/country.component';
import { CountryDetailComponent } from './country/country-detail.component';
import { DivisionDataComponent } from './division/division.component';
import { DivisionDetailComponent } from './division/division-detail.component';
import { ClubDataComponent } from './club/club.component';
import { ClubDetailComponent } from './club/club-detail.component';
import { SeasonDataComponent } from './season/season.component';
import { PlayerDataComponent } from './player/player.component';
import { PlayerDetailComponent } from './player/player-detail.component';
import { RatingsDataComponent } from './ratings/ratings.component';

const routes: Routes = [
  {
    path: '',
    component: DataComponent,
    children: [
      { path: '', redirectTo: 'player', pathMatch: 'full' },
      { path: 'country',       component: CountryDataComponent },
      { path: 'country/:id',  component: CountryDetailComponent },
      { path: 'division',      component: DivisionDataComponent },
      { path: 'division/:id',  component: DivisionDetailComponent },
      { path: 'club',     component: ClubDataComponent },
      { path: 'club/:id', component: ClubDetailComponent },
      { path: 'season',   component: SeasonDataComponent },
      { path: 'player',      component: PlayerDataComponent },
      { path: 'player/:id', component: PlayerDetailComponent },
      { path: 'ratings',    component: RatingsDataComponent },
    ]
  }
];

@NgModule({
  declarations: [
    DataComponent,
    CountryDataComponent,
    CountryDetailComponent,
    DivisionDataComponent,
    DivisionDetailComponent,
    ClubDataComponent,
    ClubDetailComponent,
    SeasonDataComponent,
    PlayerDataComponent,
    PlayerDetailComponent,
    RatingsDataComponent,
  ],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
  ]
})
export class DataModule {}
