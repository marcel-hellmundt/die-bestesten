import { Component, inject } from '@angular/core';
import { DataCacheService } from '../core/data-cache.service';

@Component({
  selector: 'app-liga',
  standalone: false,
  templateUrl: './liga.component.html',
  styleUrl: './liga.component.scss'
})
export class LigaComponent {
  cache = inject(DataCacheService);

  constructor() {
    this.cache.ensureLeague();
  }
}
