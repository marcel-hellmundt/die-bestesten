import { Component, computed, effect, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, forkJoin, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { Season } from '../../core/models/season.model';
import { Matchday } from '../../core/models/matchday.model';
import { Transferwindow } from '../../core/models/transferwindow.model';

@Component({
  selector: 'app-data-season',
  standalone: false,
  templateUrl: './season.component.html',
  styleUrl: './season.component.scss'
})
export class SeasonDataComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);

  private reload$ = new BehaviorSubject<void>(undefined);

  private seasonState = toSignal(
    this.reload$.pipe(
      switchMap(() => this.api.get<any[]>('season').pipe(
        map(data => ({ data: data.map(Season.from), loading: false, error: null as string | null })),
        startWith({ data: [] as Season[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Season[], loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  items   = computed(() => this.seasonState()?.data    ?? []);
  loading = computed(() => this.seasonState()?.loading ?? true);
  error   = computed(() => this.seasonState()?.error   ?? null);

  searchQuery   = signal('');
  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(i =>
      i.displayName.toLowerCase().includes(q) ||
      i.start_date.includes(q)
    );
  });

  selectedSeason   = signal<Season | null>(null);
  selectedMatchday = signal<Matchday | null>(null);

  // Vorauswahl: neuste Saison sobald Daten geladen sind
  private autoSelectEffect = effect(() => {
    const seasons = this.items();
    if (seasons.length > 0 && !this.selectedSeason()) {
      this.selectedSeason.set(seasons[0]);
    }
  });

  private selectedSeasonId$ = toObservable(computed(() => this.selectedSeason()?.id ?? null));

  private detailState = toSignal(
    this.selectedSeasonId$.pipe(
      switchMap(id => {
        if (!id) return of({ matchdays: [] as Matchday[], transferwindows: [] as Transferwindow[], loading: false });
        return forkJoin({
          matchdays:       this.api.get<any[]>(`matchday?season_id=${id}`),
          transferwindows: this.api.get<any[]>(`transferwindow?season_id=${id}`),
        }).pipe(
          map(({ matchdays, transferwindows }) => ({
            matchdays:       matchdays.map(Matchday.from),
            transferwindows: transferwindows.map(Transferwindow.from),
            loading: false,
          })),
          startWith({ matchdays: [] as Matchday[], transferwindows: [] as Transferwindow[], loading: true }),
        );
      })
    )
  );

  matchdays       = computed(() => this.detailState()?.matchdays       ?? []);
  transferwindows = computed(() => this.detailState()?.transferwindows ?? []);
  detailLoading   = computed(() => this.detailState()?.loading         ?? false);

  matchdayTransferwindows = computed(() => {
    const id = this.selectedMatchday()?.id;
    if (!id) return [];
    return this.transferwindows().filter(tw => tw.matchday_id === id);
  });

  transferwindowCount(matchdayId: string): number {
    return this.transferwindows().filter(tw => tw.matchday_id === matchdayId).length;
  }

  selectSeason(season: Season): void {
    this.selectedSeason.set(season);
    this.selectedMatchday.set(null);
  }

  selectMatchday(matchday: Matchday): void {
    const current = this.selectedMatchday();
    this.selectedMatchday.set(current?.id === matchday.id ? null : matchday);
  }

  isAdmin      = computed(() => this.auth.isAdmin());
  migrateState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  migrate(): void {
    this.migrateState.set('loading');
    this.api.post<any>('season/migrate').pipe(
      switchMap(() => this.api.post<any>('matchday/migrate')),
      switchMap(() => this.api.post<any>('transferwindow/migrate')),
    ).subscribe({
      next: () => {
        this.migrateState.set('success');
        this.reload$.next();
      },
      error: () => this.migrateState.set('error'),
    });
  }
}
