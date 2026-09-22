import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';

import { DatenschutzComponent } from './datenschutz.component';

const routes: Routes = [
  { path: '', component: DatenschutzComponent },
];

@NgModule({
  declarations: [DatenschutzComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
  ]
})
export class DatenschutzModule {}
