import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { ShellComponent } from './shell.component';
import { NavComponent } from './nav/nav.component';
import { AuthGuard } from '../auth/auth.guard';

const routes: Routes = [
  {
    path: '',
    component: ShellComponent,
    canActivate: [AuthGuard],
    children: [
      { path: '', redirectTo: 'data', pathMatch: 'full' },
      { path: 'data', loadChildren: () => import('../data/data.module').then(m => m.DataModule) }
    ]
  }
];

@NgModule({
  declarations: [ShellComponent, NavComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes)
  ]
})
export class ShellModule {}
