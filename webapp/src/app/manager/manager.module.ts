import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { ManagerDetailComponent } from './manager-detail.component';

const routes: Routes = [
  { path: ':id', component: ManagerDetailComponent }
];

@NgModule({
  declarations: [ManagerDetailComponent],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class ManagerModule {}
