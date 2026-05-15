import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { LigaComponent } from './liga.component';
import { MatchdayComponent } from './matchday/matchday.component';
import { TableComponent } from './table/table.component';
import { LigaTeamsComponent } from './teams/liga-teams.component';
import { HallOfFameComponent } from '../hall-of-fame/hall-of-fame.component';

const routes: Routes = [
  {
    path: '',
    component: LigaComponent,
    children: [
      { path: '',             redirectTo: 'spieltag', pathMatch: 'full' },
      { path: 'spieltag',      component: MatchdayComponent },
      { path: 'tabelle',       component: TableComponent },
      { path: 'teams',        component: LigaTeamsComponent },
      { path: 'ruhmeshalle', component: HallOfFameComponent },
    ]
  }
];

@NgModule({
  declarations: [LigaComponent, MatchdayComponent, TableComponent, LigaTeamsComponent, HallOfFameComponent],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class LigaModule {}
