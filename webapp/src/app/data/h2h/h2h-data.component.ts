import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-h2h-data',
  standalone: false,
  templateUrl: './h2h-data.component.html',
  styleUrl: './h2h-data.component.scss',
})
export class H2HDataComponent {
  private api = inject(ApiService);
  cache       = inject(DataCacheService);

  private leagueState = toSignal(
    this.api.get<any[]>('league').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: [] as any[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as any[], loading: false, error: 'Fehler beim Laden' })),
    )
  );

  leagues = computed(() => this.leagueState()?.data ?? []);
  loading = computed(() => this.leagueState()?.loading ?? true);
  error   = computed(() => this.leagueState()?.error ?? null);

  activeSeasonId = computed(() => {
    const seasons = [...this.cache.startedSeasons()]
      .sort((a, b) => b.start_date.localeCompare(a.start_date));
    return seasons[0]?.id ?? null;
  });

  activeSeasonLabel = computed(() => {
    const seasons = [...this.cache.startedSeasons()]
      .sort((a, b) => b.start_date.localeCompare(a.start_date));
    const year = seasons[0]?.start_date?.slice(0, 4);
    if (!year) return '';
    return `${year}/${String(+year + 1).slice(-2)}`;
  });

  generateStates   = signal<Record<string, 'idle' | 'loading' | 'success' | 'error'>>({});
  generateMessages = signal<Record<string, string>>({});

  generateState(leagueId: string): 'idle' | 'loading' | 'success' | 'error' {
    return this.generateStates()[leagueId] ?? 'idle';
  }

  generateMessage(leagueId: string): string {
    return this.generateMessages()[leagueId] ?? '';
  }

  generate(league: any): void {
    const seasonId = this.activeSeasonId();
    if (!seasonId) return;

    this.generateStates.update(s => ({ ...s, [league.id]: 'loading' }));
    this.generateMessages.update(s => ({ ...s, [league.id]: '' }));

    this.api.post<any>('h2h/generate', { league_id: league.id, season_id: seasonId }).subscribe({
      next: res => {
        this.generateStates.update(s => ({ ...s, [league.id]: 'success' }));
        this.generateMessages.update(s => ({
          ...s,
          [league.id]: `${res.groups} Gruppen, ${res.matches} Matches erstellt`,
        }));
      },
      error: err => {
        this.generateStates.update(s => ({ ...s, [league.id]: 'error' }));
        const msg = err?.error?.message ?? 'Fehler beim Generieren';
        this.generateMessages.update(s => ({ ...s, [league.id]: msg }));
      },
    });
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
