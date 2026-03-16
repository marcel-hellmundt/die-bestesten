import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';

import { DataComponent } from './data.component';
import { CountryDataComponent } from './country/country.component';
import { ClubDataComponent } from './club/club.component';
import { SeasonDataComponent } from './season/season.component';
import { PlayerDataComponent } from './player/player.component';
import { MatchdayDataComponent } from './matchday/matchday.component';

const routes: Routes = [
  {
    path: '',
    component: DataComponent,
    children: [
      { path: '', redirectTo: 'country', pathMatch: 'full' },
      { path: 'country',  component: CountryDataComponent },
      { path: 'club',     component: ClubDataComponent },
      { path: 'season',   component: SeasonDataComponent },
      { path: 'player',   component: PlayerDataComponent },
      { path: 'matchday', component: MatchdayDataComponent },
    ]
  }
];

@NgModule({
  declarations: [
    DataComponent,
    CountryDataComponent,
    ClubDataComponent,
    SeasonDataComponent,
    PlayerDataComponent,
    MatchdayDataComponent,
  ],
  imports: [
    CommonModule,
    RouterModule.forChild(routes)
  ]
})
export class DataModule {}
