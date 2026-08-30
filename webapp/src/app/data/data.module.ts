import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { IconModule } from '../shared/icon/icon.module';
import { MaintainerGuard } from '../auth/maintainer.guard';
import { ContributorGuard } from '../auth/contributor.guard';

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
import { LeagueDetailComponent } from './league/league-detail.component';
import { AchievementsDataComponent } from './achievements/achievements-data.component';
import { ManagerDataComponent } from './manager/manager-data.component';
import { PlayerImportDataComponent } from './player-import/player-import.component';
import { SessionHeatmapComponent } from './session/session-heatmap.component';

const M = [MaintainerGuard];
const C = [ContributorGuard];

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
      { path: 'league/:id',   component: LeagueDetailComponent,   canActivate: M },
      { path: 'club',         component: ClubDataComponent,       canActivate: M },
      { path: 'club/:id',     component: ClubDetailComponent,     canActivate: M },
      { path: 'season',       component: SeasonDataComponent,     canActivate: M },
      { path: 'ratings',      component: RatingsDataComponent,    canActivate: C },
      // player routes: no MaintainerGuard — managers may get read access here later
      { path: 'player',        component: PlayerDataComponent },
      { path: 'player/:id',    component: PlayerDetailComponent },
      { path: 'achievements',  component: AchievementsDataComponent, canActivate: M },
      { path: 'manager',       component: ManagerDataComponent,      canActivate: M },
      { path: 'player-import', component: PlayerImportDataComponent, canActivate: M },
      { path: 'session-heatmap', component: SessionHeatmapComponent, canActivate: M },
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
    LeagueDataComponent,
    LeagueDetailComponent,
    AchievementsDataComponent,
    ManagerDataComponent,
    PlayerImportDataComponent,
    SessionHeatmapComponent,
  ],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
    IconModule,
  ]
})
export class DataModule {}
