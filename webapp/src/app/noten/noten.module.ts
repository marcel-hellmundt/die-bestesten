import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';

import { NotenComponent } from './noten.component';

const routes: Routes = [
  { path: '', component: NotenComponent },
];

@NgModule({
  declarations: [NotenComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
  ]
})
export class NotenModule {}
