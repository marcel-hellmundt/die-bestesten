import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { Player } from '../../core/models/player.model';

@Component({
  selector: 'app-data-player',
  standalone: false,
  templateUrl: './player.component.html',
  styleUrl: './player.component.scss'
})
export class PlayerDataComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);

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
  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(i =>
      i.displayname.toLowerCase().includes(q) ||
      (i.first_name ?? '').toLowerCase().includes(q) ||
      (i.last_name  ?? '').toLowerCase().includes(q)
    );
  });

  isAdmin      = computed(() => this.auth.isAdmin());
  migrateState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  migrate(): void {
    this.migrateState.set('loading');
    this.api.post<{ status: boolean; migrated: number }>('player/migrate').subscribe({
      next: () => {
        this.migrateState.set('success');
        this.reload$.next();
      },
      error: () => this.migrateState.set('error'),
    });
  }
}
