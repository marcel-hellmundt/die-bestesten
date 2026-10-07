import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { LukatenAccountComponent } from './lukaten-account.component';

// /lukaten — das Lukaten-Konto: Stand, woher Lukaten kommen, wofür man sie ausgibt, Kontoauszug
const routes: Routes = [
  { path: '', component: LukatenAccountComponent },
];

@NgModule({
  declarations: [LukatenAccountComponent],
  imports: [CommonModule, RouterModule.forChild(routes)],
})
export class LukatenModule {}
