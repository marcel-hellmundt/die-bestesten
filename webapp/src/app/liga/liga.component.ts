import { Component, inject } from '@angular/core';
import { DataCacheService } from '../core/data-cache.service';
import { LigaSubnavService } from './liga-subnav.service';

@Component({
  selector: 'app-liga',
  standalone: false,
  templateUrl: './liga.component.html',
  styleUrl: './liga.component.scss'
})
export class LigaComponent {
  cache = inject(DataCacheService);
  subnav = inject(LigaSubnavService);

  constructor() {
    this.cache.ensureLeague();
  }
}
