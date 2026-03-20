import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { Division } from '../../core/data-cache.service';

@Component({
  selector: 'app-data-division',
  standalone: false,
  templateUrl: './division.component.html',
  styleUrl: './division.component.scss'
})
export class DivisionDataComponent {
  private api = inject(ApiService);

  private reload$ = new BehaviorSubject<void>(undefined);

  private state = toSignal(
    this.reload$.pipe(
      switchMap(() => this.api.get<Division[]>('division').pipe(
        map(data => ({ data, loading: false, error: null as string | null })),
        startWith({ data: [] as Division[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Division[], loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);

  searchQuery   = signal('');
  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(i => i.name.toLowerCase().includes(q));
  });
}
