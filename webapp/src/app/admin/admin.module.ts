import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { IconModule } from '../shared/icon/icon.module';
import { StuckDirective } from '../core/stuck.directive';
import { SegmentedDirective } from '../core/segmented.directive';

import { AdminComponent } from './admin.component';
import { LeagueDataComponent } from './league/league.component';
import { LeagueDetailComponent } from './league/league-detail.component';
import { ManagerDataComponent } from './manager/manager-data.component';
import { AchievementsDataComponent } from './achievements/achievements-data.component';
import { SessionHeatmapComponent } from './session/session-heatmap.component';
import { SessionDevicesComponent } from './session/session-devices.component';
import { LukatenModeComponent } from './lukaten/lukaten-mode.component';

// /verwaltung — nur Admin (AdminGuard an der Route in shell.module.ts, gilt für alle Unterseiten)
const routes: Routes = [
  {
    path: '',
    component: AdminComponent,
    children: [
      { path: '', redirectTo: 'ligen', pathMatch: 'full' },
      { path: 'ligen',        component: LeagueDataComponent },
      { path: 'ligen/:id',    component: LeagueDetailComponent },
      { path: 'manager',      component: ManagerDataComponent },
      { path: 'achievements', component: AchievementsDataComponent },
      { path: 'lukaten',      component: LukatenModeComponent },
      { path: 'nutzung',      component: SessionHeatmapComponent },
    ],
  },
];

@NgModule({
  declarations: [
    AdminComponent,
    LeagueDataComponent,
    LeagueDetailComponent,
    ManagerDataComponent,
    AchievementsDataComponent,
    SessionHeatmapComponent,
    SessionDevicesComponent,
    LukatenModeComponent,
  ],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
    IconModule,
    StuckDirective,
    SegmentedDirective,
  ],
})
export class AdminModule {}
