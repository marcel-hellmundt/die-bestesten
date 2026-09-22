import { NgModule } from '@angular/core';
import { RouterModule, Routes } from '@angular/router';

const routes: Routes = [
  { path: 'login', loadChildren: () => import('./auth/auth.module').then(m => m.AuthModule) },
  // Guest-erreichbar wie /login — bewusst außerhalb von ShellModule (dessen Root-Route per
  // AuthGuard abgesichert ist), damit die Seite ohne Login funktioniert.
  { path: 'noten', loadChildren: () => import('./noten/noten.module').then(m => m.NotenModule) },
  // Guest-erreichbar wie /noten — rechtlich vorgeschriebene Seiten (§ 5 DDG, Art. 13 DSGVO) müssen
  // ohne Login erreichbar sein.
  { path: 'impressum', loadChildren: () => import('./legal/impressum/impressum.module').then(m => m.ImpressumModule) },
  { path: 'datenschutz', loadChildren: () => import('./legal/datenschutz/datenschutz.module').then(m => m.DatenschutzModule) },
  { path: '', loadChildren: () => import('./shell/shell.module').then(m => m.ShellModule) },
  { path: '**', redirectTo: '' }
];

@NgModule({
  imports: [RouterModule.forRoot(routes)],
  exports: [RouterModule]
})
export class AppRoutingModule {}
