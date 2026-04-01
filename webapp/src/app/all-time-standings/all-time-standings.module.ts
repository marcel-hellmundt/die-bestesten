import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { AllTimeStandingsComponent } from './all-time-standings.component';

const routes: Routes = [
  { path: '', component: AllTimeStandingsComponent }
];

@NgModule({
  declarations: [AllTimeStandingsComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
  ]
})
export class AllTimeStandingsModule {}
