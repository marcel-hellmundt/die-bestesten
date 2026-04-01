import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { AllTimeStandingsComponent } from './all-time-standings.component';
import { ManagerDetailComponent } from './manager-detail.component';

const routes: Routes = [
  { path: '', component: AllTimeStandingsComponent },
  { path: ':id', component: ManagerDetailComponent }
];

@NgModule({
  declarations: [AllTimeStandingsComponent, ManagerDetailComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
  ]
})
export class AllTimeStandingsModule {}
