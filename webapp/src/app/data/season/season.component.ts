import { Component, computed, effect, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, combineLatest, forkJoin, map, of, startWith, switchMap } from 'rxjs';
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

  private reload$       = new BehaviorSubject<void>(undefined);
  private detailReload$ = new BehaviorSubject<void>(undefined);

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
    combineLatest([this.selectedSeasonId$, this.detailReload$]).pipe(
      switchMap(([id]) => {
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

  selectSeasonById(id: string): void {
    const season = this.items().find(s => s.id === id);
    if (season) this.selectSeason(season);
  }

  selectSeason(season: Season): void {
    this.selectedSeason.set(season);
    this.selectedMatchday.set(null);
  }

  selectMatchday(matchday: Matchday): void {
    const current = this.selectedMatchday();
    this.selectedMatchday.set(current?.id === matchday.id ? null : matchday);
  }

  private readonly WEEKDAYS = ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'];

  formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
  }

  formatDatetime(dateStr: string | null): string {
    if (!dateStr) return '—';
    const dt = new Date(dateStr.replace(' ', 'T'));
    const weekday = this.WEEKDAYS[dt.getDay()];
    const d = String(dt.getDate()).padStart(2, '0');
    const m = String(dt.getMonth() + 1).padStart(2, '0');
    const y = dt.getFullYear();
    const h = String(dt.getHours()).padStart(2, '0');
    const min = String(dt.getMinutes()).padStart(2, '0');
    return `${weekday} ${d}.${m}.${y} ${h}:${min}`;
  }

  isAdmin       = computed(() => this.auth.isAdmin());
  isMaintainer  = computed(() => this.auth.isMaintainer());
  migrateState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  sanityState    = signal<'idle' | 'loading' | 'done'>('idle');
  sanityWarnings = signal<string[]>([]);

  runSanityCheck(): void {
    this.sanityState.set('loading');
    forkJoin({
      matchdays:       this.api.get<any[]>('matchday'),
      transferwindows: this.api.get<any[]>('transferwindow'),
    }).subscribe({
      next: ({ matchdays, transferwindows }) => {
        const warnings: string[] = [];
        const mds = matchdays.map(Matchday.from);
        const tws = transferwindows.map(Transferwindow.from);
        const seasonMap = new Map(this.items().map(s => [s.id, s.displayName]));
        const matchdayMap = new Map(mds.map(m => [m.id, m]));

        // Saisons: Start muss 1. Juli sein
        for (const s of this.items()) {
          const [, mm, dd] = s.start_date.split('-');
          if (mm !== '07' || dd !== '01') {
            warnings.push(`Saison ${s.displayName}: Start ${this.formatDate(s.start_date)} ist nicht der 1. Juli`);
          }
        }

        // Spieltage: Anpfiff muss Fr. 20:00, Fr. 20:30, Sa. 15:30 oder Di. 18:30 sein
        for (const md of mds) {
          if (!md.kickoff_date) continue;
          const dt = new Date(md.kickoff_date.replace(' ', 'T'));
          const day = dt.getDay();
          const h = dt.getHours();
          const min = dt.getMinutes();
          const valid =
            (day === 5 && h === 20 && min === 0)  ||
            (day === 5 && h === 20 && min === 30) ||
            (day === 6 && h === 15 && min === 30) ||
            (day === 2 && h === 18 && min === 30);
          if (!valid) {
            const sName = seasonMap.get(md.season_id) ?? md.season_id;
            warnings.push(`Spieltag ${md.number} (${sName}): Anpfiff ${this.formatDatetime(md.kickoff_date)} ist ungewöhnlich`);
          }
        }

        // Transferfenster: Ende muss um 20:00 (beliebiger Tag) oder Fr. um 15:00 sein
        for (const tw of tws) {
          const dt = new Date(tw.end_date.replace(' ', 'T'));
          const day = dt.getDay();
          const h = dt.getHours();
          const min = dt.getMinutes();
          const valid =
            (h === 20 && min === 0) ||
            (day === 5 && h === 15 && min === 0);
          if (!valid) {
            const md = matchdayMap.get(tw.matchday_id);
            const sName = md ? (seasonMap.get(md.season_id) ?? '') : '';
            const label = md ? `Spieltag ${md.number} (${sName})` : tw.matchday_id;
            warnings.push(`TF ${label}: Ende ${this.formatDatetime(tw.end_date)} ist ungewöhnlich`);
          }
        }

        // Transferfenster: keine Überschneidungen
        const sorted = [...tws].sort((a, b) => a.start_date.localeCompare(b.start_date));
        for (let i = 1; i < sorted.length; i++) {
          const prev = sorted[i - 1];
          const curr = sorted[i];
          if (curr.start_date < prev.end_date) {
            const getMd = (tw: Transferwindow) => {
              const m = matchdayMap.get(tw.matchday_id);
              return m ? `Spieltag ${m.number} (${seasonMap.get(m.season_id) ?? ''})` : tw.matchday_id;
            };
            warnings.push(`Überschneidung: TF ${getMd(prev)} [${prev.id}] (bis ${this.formatDatetime(prev.end_date)}) und TF ${getMd(curr)} [${curr.id}] (ab ${this.formatDatetime(curr.start_date)})`);
          }
        }

        this.sanityWarnings.set(warnings);
        this.sanityState.set('done');
      },
      error: () => this.sanityState.set('idle'),
    });
  }

  addingMatchdayId = signal<string | null>(null);
  twFormStart      = signal('');
  twFormEnd        = signal('');
  twSaveState      = signal<'idle' | 'loading' | 'error'>('idle');
  twSaveError      = signal('');

  addTransferwindow(matchday: Matchday): void {
    this.addingMatchdayId.set(matchday.id);
    this.twFormStart.set('');
    this.twFormEnd.set('');
    this.twSaveState.set('idle');
    this.twSaveError.set('');
  }

  cancelTwForm(): void {
    this.addingMatchdayId.set(null);
  }

  private toMysqlDatetime(localDt: string): string {
    return localDt.replace('T', ' ') + ':00';
  }

  submitTwForm(matchday: Matchday): void {
    if (this.twFormStart() >= this.twFormEnd()) {
      this.twSaveState.set('error');
      this.twSaveError.set('Start muss vor Ende liegen');
      return;
    }
    this.twSaveState.set('loading');
    this.api.post<any>('transferwindow', {
      matchday_id: matchday.id,
      start_date:  this.toMysqlDatetime(this.twFormStart()),
      end_date:    this.toMysqlDatetime(this.twFormEnd()),
    }).subscribe({
      next: () => {
        this.addingMatchdayId.set(null);
        this.twSaveState.set('idle');
        this.detailReload$.next();
      },
      error: (err) => {
        this.twSaveState.set('error');
        this.twSaveError.set(err?.error?.message ?? 'Fehler beim Speichern');
      },
    });
  }

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
