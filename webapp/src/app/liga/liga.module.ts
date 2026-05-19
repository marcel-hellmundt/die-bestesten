import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { LigaComponent } from './liga.component';
import { MatchdayComponent } from './matchday/matchday.component';
import { TableComponent } from './table/table.component';
import { LigaTeamsComponent } from './teams/liga-teams.component';
import { HallOfFameComponent } from '../hall-of-fame/hall-of-fame.component';
import { H2HComponent } from './h2h/h2h.component';
import { H2HMatchComponent } from './h2h/h2h-match.component';
import { H2HModusComponent } from './h2h/h2h-modus.component';

const routes: Routes = [
  {
    path: '',
    component: LigaComponent,
    children: [
      { path: '',             redirectTo: 'spieltag', pathMatch: 'full' },
      { path: 'spieltag',     component: MatchdayComponent },
      { path: 'tabelle',      component: TableComponent },
      { path: 'h2h',          component: H2HComponent },
      { path: 'h2h/modus',    component: H2HModusComponent },
      { path: 'h2h/:id',      component: H2HMatchComponent },
      { path: 'teams',        component: LigaTeamsComponent },
      { path: 'ruhmeshalle',  component: HallOfFameComponent },
    ]
  }
];

@NgModule({
  declarations: [LigaComponent, MatchdayComponent, TableComponent, LigaTeamsComponent, HallOfFameComponent, H2HComponent, H2HMatchComponent, H2HModusComponent],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class LigaModule {}
