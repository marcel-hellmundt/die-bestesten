import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { SquadComponent } from './squad/squad.component';
import { LineupComponent } from './lineup/lineup.component';
import { FinancesComponent } from './finances/finances.component';
import { TeamStatsComponent } from './stats/team-stats.component';

const routes: Routes = [
  { path: '', redirectTo: 'kader', pathMatch: 'full' },
  { path: 'kader',       component: SquadComponent },
  { path: 'aufstellung', component: LineupComponent },
  { path: 'finanzen',    component: FinancesComponent },
  { path: 'statistiken', component: TeamStatsComponent },
];

@NgModule({
  declarations: [SquadComponent, LineupComponent, FinancesComponent, TeamStatsComponent],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class TeamModule {}
