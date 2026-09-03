import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { DragDropModule } from '@angular/cdk/drag-drop';
import { LigaComponent } from './liga.component';
import { MatchdayComponent } from './matchday/matchday.component';
import { TableComponent } from './table/table.component';
import { LigaTeamsComponent } from './teams/liga-teams.component';
import { HallOfFameComponent } from '../hall-of-fame/hall-of-fame.component';
import { H2HComponent } from './h2h/h2h.component';
import { H2HMatchComponent } from './h2h/h2h-match.component';
import { H2HModeComponent } from './h2h/h2h-mode.component';
import { BettingOfficeComponent } from './h2h/betting-office.component';
import { PowerrankingComponent } from './powerranking/powerranking.component';
import { HotTakesComponent } from './hot-takes/hot-takes.component';

const routes: Routes = [
  {
    path: '',
    component: LigaComponent,
    children: [
      { path: '',             redirectTo: 'spieltag', pathMatch: 'full' },
      { path: 'spieltag',     component: MatchdayComponent },
      { path: 'tabelle',      component: TableComponent },
      { path: 'powerranking', component: PowerrankingComponent },
      { path: 'h2h',          component: H2HComponent },
      { path: 'h2h/modus',    component: H2HModeComponent },
      { path: 'h2h/wettbüro', component: BettingOfficeComponent },
      { path: 'h2h/:id',      component: H2HMatchComponent },
      { path: 'teams',        component: LigaTeamsComponent },
      { path: 'ruhmeshalle',  component: HallOfFameComponent },
      { path: 'hot-takes',    component: HotTakesComponent },
    ]
  }
];

@NgModule({
  declarations: [LigaComponent, MatchdayComponent, TableComponent, LigaTeamsComponent, HallOfFameComponent, H2HComponent, H2HMatchComponent, H2HModeComponent, BettingOfficeComponent, PowerrankingComponent, HotTakesComponent],
  imports: [CommonModule, RouterModule.forChild(routes), DragDropModule]
})
export class LigaModule {}
