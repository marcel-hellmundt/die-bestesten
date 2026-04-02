import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { MatchdayComponent } from './matchday/matchday.component';
import { TableComponent } from './table/table.component';
import { LigaStatsComponent } from './stats/liga-stats.component';
import { AllTimeStandingsComponent } from '../all-time-standings/all-time-standings.component';

const routes: Routes = [
  { path: '', redirectTo: 'spieltag', pathMatch: 'full' },
  { path: 'spieltag',      component: MatchdayComponent },
  { path: 'tabelle',       component: TableComponent },
  { path: 'statistiken',   component: LigaStatsComponent },
  { path: 'ewige-tabelle', component: AllTimeStandingsComponent },
];

@NgModule({
  declarations: [MatchdayComponent, TableComponent, LigaStatsComponent, AllTimeStandingsComponent],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class LigaModule {}
