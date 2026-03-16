import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { DataComponent } from './data.component';

const routes: Routes = [
  { path: '', component: DataComponent }
];

@NgModule({
  declarations: [DataComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes)
  ]
})
export class DataModule {}
