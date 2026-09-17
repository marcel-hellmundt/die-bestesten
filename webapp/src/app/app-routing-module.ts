import { NgModule } from '@angular/core';
import { RouterModule, Routes } from '@angular/router';

const routes: Routes = [
  { path: 'login', loadChildren: () => import('./auth/auth.module').then(m => m.AuthModule) },
  // Guest-erreichbar wie /login — bewusst außerhalb von ShellModule (dessen Root-Route per
  // AuthGuard abgesichert ist), damit die Seite ohne Login funktioniert.
  { path: 'noten', loadChildren: () => import('./noten/noten.module').then(m => m.NotenModule) },
  { path: '', loadChildren: () => import('./shell/shell.module').then(m => m.ShellModule) },
  { path: '**', redirectTo: '' }
];

@NgModule({
  imports: [RouterModule.forRoot(routes)],
  exports: [RouterModule]
})
export class AppRoutingModule {}
