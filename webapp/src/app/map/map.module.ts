import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { GoogleMapsModule } from '@angular/google-maps';
import { IconModule } from '../shared/icon/icon.module';
import { MapComponent } from './map.component';

const routes: Routes = [
  { path: '', component: MapComponent }
];

@NgModule({
  declarations: [MapComponent],
  imports: [
    CommonModule,
    RouterModule.forChild(routes),
    GoogleMapsModule,
    IconModule,
  ]
})
export class MapModule {}
