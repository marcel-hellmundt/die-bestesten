import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { Matchday } from '../../core/models/matchday.model';

@Component({
  selector: 'app-data-matchday',
  standalone: false,
  templateUrl: './matchday.component.html',
  styleUrl: './matchday.component.scss'
})
export class MatchdayDataComponent {
  private api   = inject(ApiService);
  private auth  = inject(AuthService);
  cache         = inject(DataCacheService);

  private reload$ = new BehaviorSubject<void>(undefined);

  constructor() {
    this.cache.ensureSeasons();
  }

  private state = toSignal(
    this.reload$.pipe(
      switchMap(() => this.api.get<any[]>('matchday').pipe(
        map(data => ({ data: data.map(Matchday.from), loading: false, error: null as string | null })),
        startWith({ data: [] as Matchday[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Matchday[], loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);

  isAdmin      = computed(() => this.auth.isAdmin());
  migrateState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  migrate(): void {
    this.migrateState.set('loading');
    this.api.post<{ status: boolean; migrated: number }>('matchday/migrate').subscribe({
      next: () => {
        this.migrateState.set('success');
        this.reload$.next();
      },
      error: () => this.migrateState.set('error'),
    });
  }
}
