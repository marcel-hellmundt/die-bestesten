import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { AchievementsComponent } from './achievements.component';
import { FitTextDirective } from './fit-text.directive';

const routes: Routes = [
  { path: '', component: AchievementsComponent }
];

@NgModule({
  declarations: [AchievementsComponent, FitTextDirective],
  imports: [CommonModule, RouterModule.forChild(routes)]
})
export class AchievementsModule {}
