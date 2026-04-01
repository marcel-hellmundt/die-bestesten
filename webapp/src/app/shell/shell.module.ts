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
      { path: '', redirectTo: 'data', pathMatch: 'full' },
      { path: 'data',          loadChildren: () => import('../data/data.module').then(m => m.DataModule) },
      { path: 'settings',      loadChildren: () => import('../settings/settings.module').then(m => m.SettingsModule) },
      { path: 'all-time-standings', loadChildren: () => import('../all-time-standings/all-time-standings.module').then(m => m.AllTimeStandingsModule) },
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
