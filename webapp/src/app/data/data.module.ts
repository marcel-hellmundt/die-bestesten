import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { IconModule } from '../shared/icon/icon.module';
import { StuckDirective } from '../core/stuck.directive';
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
import { PlayerImportDataComponent } from './player-import/player-import.component';

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
      // club + player: Liste ab Maintainer, Detailseite ohne Guard für alle Manager lesbar (Suche, Karte,
      // Kader, Transfers verlinken darauf) — Bearbeiten ist dort in den Komponenten an die Rolle gebunden
      { path: 'club',         component: ClubDataComponent,       canActivate: M },
      { path: 'club/:id',     component: ClubDetailComponent },
      { path: 'season',       component: SeasonDataComponent,     canActivate: M },
      { path: 'ratings',      component: RatingsDataComponent,    canActivate: C },
      { path: 'player',        component: PlayerDataComponent,    canActivate: M },
      { path: 'player/:id',    component: PlayerDetailComponent },
      { path: 'player-import', component: PlayerImportDataComponent, canActivate: M },
      // Ligen, Manager, Achievements, Nutzung liegen jetzt unter /verwaltung (AdminModule) — alte Pfade (Lesezeichen)
      { path: 'league',          redirectTo: '/verwaltung/ligen' },
      { path: 'league/:id',      redirectTo: '/verwaltung/ligen/:id' },
      { path: 'manager',         redirectTo: '/verwaltung/manager' },
      { path: 'achievements',    redirectTo: '/verwaltung/achievements' },
      { path: 'nutzung',         redirectTo: '/verwaltung/nutzung' },
      { path: 'session-heatmap', redirectTo: '/verwaltung/nutzung' },
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
    PlayerImportDataComponent,
  ],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
    IconModule,
    StuckDirective,
  ]
})
export class DataModule {}
