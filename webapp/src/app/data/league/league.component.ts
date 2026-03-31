import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { League } from '../../core/models/league.model';

@Component({
  selector: 'app-data-league',
  standalone: false,
  templateUrl: './league.component.html',
  styleUrl: './league.component.scss'
})
export class LeagueDataComponent {
  private api = inject(ApiService);

  private state = toSignal(
    this.api.get<any[]>('league').pipe(
      map(data => ({ data: data.map(League.from), loading: false, error: null as string | null })),
      startWith({ data: [] as League[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as League[], loading: false, error: 'Fehler beim Laden' }))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);

  searchQuery   = signal('');
  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(i =>
      i.name.toLowerCase().includes(q) ||
      i.slug.toLowerCase().includes(q)
    );
  });
}
