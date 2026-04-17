import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { IconComponent } from '../shared/icon/icon.component';
import { MaintainerGuard } from '../auth/maintainer.guard';

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
import { LeagueDataComponent } from './league/league.component';

const M = [MaintainerGuard];

const routes: Routes = [
  {
    path: '',
    component: DataComponent,
    children: [
      { path: '', redirectTo: 'ratings', pathMatch: 'full' },
      { path: 'country',      component: CountryDataComponent,    canActivate: M },
      { path: 'country/:id',  component: CountryDetailComponent,  canActivate: M },
      { path: 'division',     component: DivisionDataComponent,   canActivate: M },
      { path: 'division/:id', component: DivisionDetailComponent, canActivate: M },
      { path: 'league',       component: LeagueDataComponent,     canActivate: M },
      { path: 'club',         component: ClubDataComponent,       canActivate: M },
      { path: 'club/:id',     component: ClubDetailComponent,     canActivate: M },
      { path: 'season',       component: SeasonDataComponent,     canActivate: M },
      { path: 'ratings',      component: RatingsDataComponent,    canActivate: M },
      // player routes: no MaintainerGuard — managers may get read access here later
      { path: 'player',      component: PlayerDataComponent },
      { path: 'player/:id',  component: PlayerDetailComponent },
    ]
  }
];

@NgModule({
  declarations: [
    IconComponent,
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
    LeagueDataComponent,
  ],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
  ]
})
export class DataModule {}
