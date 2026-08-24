import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { DragDropModule } from '@angular/cdk/drag-drop';
import { LigaComponent } from './liga.component';
import { SaisonvorschauComponent } from './saisonvorschau/saisonvorschau.component';
import { MatchdayComponent } from './matchday/matchday.component';
import { TableComponent } from './table/table.component';
import { LigaTeamsComponent } from './teams/liga-teams.component';
import { HallOfFameComponent } from '../hall-of-fame/hall-of-fame.component';
import { H2HComponent } from './h2h/h2h.component';
import { H2HMatchComponent } from './h2h/h2h-match.component';
import { H2HModeComponent } from './h2h/h2h-mode.component';
import { PowerrankingComponent } from './powerranking/powerranking.component';

const routes: Routes = [
  {
    path: '',
    component: LigaComponent,
    children: [
      { path: '',             redirectTo: 'spieltag', pathMatch: 'full' },
      { path: 'saisonvorschau', component: SaisonvorschauComponent },
      { path: 'spieltag',     component: MatchdayComponent },
      { path: 'tabelle',      component: TableComponent },
      { path: 'powerranking', component: PowerrankingComponent },
      { path: 'h2h',          component: H2HComponent },
      { path: 'h2h/modus',    component: H2HModeComponent },
      { path: 'h2h/:id',      component: H2HMatchComponent },
      { path: 'teams',        component: LigaTeamsComponent },
      { path: 'ruhmeshalle',  component: HallOfFameComponent },
    ]
  }
];

@NgModule({
  declarations: [LigaComponent, SaisonvorschauComponent, MatchdayComponent, TableComponent, LigaTeamsComponent, HallOfFameComponent, H2HComponent, H2HMatchComponent, H2HModeComponent, PowerrankingComponent],
  imports: [CommonModule, RouterModule.forChild(routes), DragDropModule]
})
export class LigaModule {}
