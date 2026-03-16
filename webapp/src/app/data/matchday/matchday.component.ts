import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';

@Component({
  selector: 'app-data-matchday',
  standalone: false,
  templateUrl: './matchday.component.html',
  styleUrl: './matchday.component.scss'
})
export class MatchdayDataComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);

  private state = toSignal(
    this.api.get<any[]>('matchday').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: [] as any[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as any[], loading: false, error: 'Fehler beim Laden' }))
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
      next: () => this.migrateState.set('success'),
      error: () => this.migrateState.set('error'),
    });
  }
}
