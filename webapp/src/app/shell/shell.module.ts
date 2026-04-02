import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { ShellComponent } from './shell.component';
import { NavComponent } from './nav/nav.component';
import { TopbarComponent } from './topbar/topbar.component';
import { AuthGuard } from '../auth/auth.guard';
import { IconComponent } from '../core/icon/icon.component';

const routes: Routes = [
  {
    path: '',
    component: ShellComponent,
    canActivate: [AuthGuard],
    children: [
      { path: '', redirectTo: 'liga', pathMatch: 'full' },
      { path: 'liga',          loadChildren: () => import('../liga/liga.module').then(m => m.LigaModule) },
      { path: 'team',          loadChildren: () => import('../team/team.module').then(m => m.TeamModule) },
      { path: 'markt',         loadChildren: () => import('../markt/markt.module').then(m => m.MarktModule) },
      { path: 'manager',       loadChildren: () => import('../manager/manager.module').then(m => m.ManagerModule) },
      { path: 'daten',         loadChildren: () => import('../data/data.module').then(m => m.DataModule) },
      { path: 'einstellungen', loadChildren: () => import('../settings/settings.module').then(m => m.SettingsModule) },
    ]
  }
];

@NgModule({
  declarations: [ShellComponent, NavComponent, TopbarComponent, IconComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes)
  ]
})
export class ShellModule {}
