import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { Country } from '../../core/models/country.model';

@Component({
  selector: 'app-data-country',
  standalone: false,
  templateUrl: './country.component.html',
  styleUrl: './country.component.scss'
})
export class CountryDataComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);

  private reload$ = new BehaviorSubject<void>(undefined);

  private state = toSignal(
    this.reload$.pipe(
      switchMap(() => this.api.get<any[]>('country').pipe(
        map(data => ({ data: data.map(Country.from), loading: false, error: null as string | null })),
        startWith({ data: [] as Country[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Country[], loading: false, error: 'Fehler beim Laden' }))
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

  isAdmin      = computed(() => this.auth.isAdmin());
  migrateState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  migrate(): void {
    this.migrateState.set('loading');
    this.api.post<{ status: boolean; migrated: number }>('country/migrate').subscribe({
      next: () => {
        this.migrateState.set('success');
        this.reload$.next();
      },
      error: () => this.migrateState.set('error'),
    });
  }
}
