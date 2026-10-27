import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { SegmentedDirective } from '../core/segmented.directive';
import { SlotMachineComponent } from './slot-machine/slot-machine.component';

// /spielhalle — Spiele um Lukaten; bisher der einarmige Bandit
const routes: Routes = [
  { path: '', component: SlotMachineComponent },
];

@NgModule({
  declarations: [SlotMachineComponent],
  imports: [CommonModule, RouterModule.forChild(routes), SegmentedDirective], // Einsatz-Wahl als .segmented
})
export class ArcadeModule {}
