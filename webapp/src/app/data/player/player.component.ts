import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { Player } from '../../core/models/player.model';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-data-player',
  standalone: false,
  templateUrl: './player.component.html',
  styleUrl: './player.component.scss'
})
export class PlayerDataComponent {
  private api    = inject(ApiService);
  private router = inject(Router);
  private route  = inject(ActivatedRoute);

  navigate(id: string): void { this.router.navigate([id], { relativeTo: this.route }); }
  cache = inject(DataCacheService);

  private reload$ = new BehaviorSubject<void>(undefined);

  private state = toSignal(
    this.reload$.pipe(
      switchMap(() => this.api.get<any[]>('player').pipe(
        map(data => ({ data: data.map(Player.from), loading: false, error: null as string | null })),
        startWith({ data: [] as Player[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Player[], loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);

  searchQuery   = signal('');
  sortCol       = signal<'displayname' | 'total_points'>('total_points');
  sortDir       = signal<'asc' | 'desc'>('desc');

  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(i =>
      i.displayname.toLowerCase().includes(q) ||
      (i.first_name ?? '').toLowerCase().includes(q) ||
      (i.last_name  ?? '').toLowerCase().includes(q)
    );
  });

  sortedItems = computed(() => {
    const col = this.sortCol();
    const dir = this.sortDir();
    return [...this.filteredItems()].sort((a, b) => {
      const cmp = col === 'total_points'
        ? a.total_points - b.total_points
        : a.displayname.localeCompare(b.displayname);
      return dir === 'asc' ? cmp : -cmp;
    });
  });

  sort(col: 'displayname' | 'total_points'): void {
    if (this.sortCol() === col) {
      this.sortDir.update(d => d === 'asc' ? 'desc' : 'asc');
    } else {
      this.sortCol.set(col);
      this.sortDir.set(col === 'total_points' ? 'desc' : 'asc');
    }
  }

  bundesligaCount = toSignal(
    this.api.get<{ count: number }>('player_in_season/bundesliga_count').pipe(
      map(res => res.count),
      catchError(() => of(null))
    )
  );

  constructor() {
    this.cache.ensureLeague();
    this.cache.ensureDivisions();
  }
}
