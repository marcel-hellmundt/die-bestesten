import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { TeamDetailComponent } from './team-detail/team-detail.component';
import { TeamOverviewComponent } from './overview/team-overview.component';
import { SquadComponent } from './squad/squad.component';
import { LineupComponent } from './lineup/lineup.component';

const routes: Routes = [
  {
    path: ':id',
    component: TeamDetailComponent,
    children: [
      { path: '',           redirectTo: 'uebersicht', pathMatch: 'full' },
      { path: 'uebersicht', component: TeamOverviewComponent },
      { path: 'kader',      component: SquadComponent },
      { path: 'aufstellung', component: LineupComponent },
    ]
  }
];

@NgModule({
  declarations: [TeamDetailComponent, TeamOverviewComponent, SquadComponent, LineupComponent],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class TeamModule {}
