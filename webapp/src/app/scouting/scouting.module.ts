import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { ScoutingComponent } from './scouting.component';

const routes: Routes = [
  { path: '', component: ScoutingComponent }
];

@NgModule({
  declarations: [ScoutingComponent],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class ScoutingModule {}
