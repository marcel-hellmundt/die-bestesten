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
import { LukatenOverviewComponent } from './lukaten/lukaten-overview.component';
import { MatchdaySummaryPreviewComponent } from './matchday-summary/matchday-summary-preview.component';
import { UiTestsComponent } from './ui-tests/ui-tests.component';
import { PackGalleryModule } from '../stickers/packs/pack-gallery.module';

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
      { path: 'lukaten',      component: LukatenOverviewComponent },
      { path: 'nutzung',      component: SessionHeatmapComponent },
      // Test-Oberflächen für Admins an einer Stelle (Spieltags-Abschluss, Sticker-Packs)
      { path: 'ui-tests',     component: UiTestsComponent },
      { path: 'zusammenfassung', redirectTo: 'ui-tests' },
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
    LukatenOverviewComponent,
    MatchdaySummaryPreviewComponent,
    UiTestsComponent,
  ],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
    IconModule,
    StuckDirective,
    SegmentedDirective,
    PackGalleryModule, // Pack-Testseite unter UI-Tests
  ],
})
export class AdminModule {}
