import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { ShellComponent } from './shell.component';
import { NavComponent } from './nav/nav.component';
import { TopbarComponent } from './topbar/topbar.component';
import { AuthGuard } from '../auth/auth.guard';
import { IconComponent } from '../core/icon/icon.component';
import { AchievementNotificationComponent } from './achievement-notification/achievement-notification.component';
import { BottomSheetComponent } from './bottom-sheet/bottom-sheet.component';
import { CreateTeamComponent } from './create-team/create-team.component';

const routes: Routes = [
  {
    path: '',
    component: ShellComponent,
    canActivate: [AuthGuard],
    children: [
      { path: '', loadChildren: () => import('../home/home.module').then(m => m.HomeModule) },
      { path: 'liga',          loadChildren: () => import('../liga/liga.module').then(m => m.LigaModule) },
      { path: 'team',          loadChildren: () => import('../team/team.module').then(m => m.TeamModule) },
      { path: 'markt',         loadChildren: () => import('../markt/markt.module').then(m => m.MarktModule) },
      { path: 'manager',       loadChildren: () => import('../manager/manager.module').then(m => m.ManagerModule) },
      { path: 'daten',         loadChildren: () => import('../data/data.module').then(m => m.DataModule) },
      { path: 'karte',         loadChildren: () => import('../map/map.module').then(m => m.MapModule) },
      { path: 'einstellungen',  loadChildren: () => import('../settings/settings.module').then(m => m.SettingsModule) },
      { path: 'achievements',    loadChildren: () => import('../achievements/achievements.module').then(m => m.AchievementsModule) },
      { path: 'scouting',       loadChildren: () => import('../scouting/scouting.module').then(m => m.ScoutingModule) },
      { path: 'benachrichtigungen', loadChildren: () => import('../notifications/notifications.module').then(m => m.NotificationsModule) },
    ]
  }
];

@NgModule({
  declarations: [ShellComponent, NavComponent, TopbarComponent, IconComponent, AchievementNotificationComponent, BottomSheetComponent, CreateTeamComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes)
  ]
})
export class ShellModule {}
