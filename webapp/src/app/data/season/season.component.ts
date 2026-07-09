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

  // Auto-select: newest season once data is loaded
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

  // Highest-numbered matchday whose start_date has already begun — same "current matchday"
  // definition used on the home page. matchdays() is already sorted DESC by number.
  currentMatchdayId = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return this.matchdays().find(m => m.start_date <= today)?.id ?? null;
  });

  matchdayTransferwindows = computed(() => {
    const id = this.selectedMatchday()?.id;
    if (!id) return [];
    return this.transferwindows().filter(tw => tw.matchday_id === id);
  });

  transferwindowCount(matchdayId: string): number {
    return this.transferwindows().filter(tw => tw.matchday_id === matchdayId).length;
  }

  private kickoffWeekday(md: Matchday): number {
    return new Date(md.kickoff_date.replace(' ', 'T')).getDay();
  }

  // Friday-kickoff matchdays (and the special Saturday-kickoff matchday 34, the season
  // finale) are expected to have at least 2 transfer windows — unless the previous
  // matchday kicked off on a Tuesday, which leaves no time to open one.
  hasLowTransferwindowCount(md: Matchday): boolean {
    const requiresTwo = this.kickoffWeekday(md) === 5 || md.number === 34;
    if (!requiresTwo) return false;

    const previous = this.matchdays().find(m => m.number === md.number - 1);
    if (previous && this.kickoffWeekday(previous) === 2) return false;

    return this.transferwindowCount(md.id) < 2;
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

        // Seasons: start must be July 1st or September 1st
        for (const s of this.items()) {
          const [, mm, dd] = s.start_date.split('-');
          if (!((mm === '07' && dd === '01') || (mm === '09' && dd === '01'))) {
            warnings.push(`Saison ${s.displayName}: Start ${this.formatDate(s.start_date)} ist nicht der 1. Juli oder 1. September`);
          }
        }

        // Matchdays: kickoff must be Fri 20:00, Fri 20:30, Sat 15:30 or Tue 18:30
        // Exception: seasons up to and including 2019/2020 — kickoff on July 1st at 00:00 is allowed
        const earlySeasonIds = new Set(
          this.items()
            .filter(s => parseInt(s.start_date.substring(0, 4), 10) <= 2019)
            .map(s => s.id)
        );
        for (const md of mds) {
          if (!md.kickoff_date) continue;
          const dt = new Date(md.kickoff_date.replace(' ', 'T'));
          const day = dt.getDay();
          const h = dt.getHours();
          const min = dt.getMinutes();
          const isEarlyJuly1Exception = earlySeasonIds.has(md.season_id)
            && md.kickoff_date.substring(5, 10) === '07-01'
            && h === 0 && min === 0;
          const valid =
            isEarlyJuly1Exception ||
            (day === 5 && h === 20 && min === 0)  ||
            (day === 5 && h === 20 && min === 30) ||
            (day === 6 && h === 15 && min === 30) ||
            (day === 2 && h === 18 && min === 30);
          if (!valid) {
            const sName = seasonMap.get(md.season_id) ?? md.season_id;
            warnings.push(`Spieltag ${md.number} (${sName}): Anpfiff ${this.formatDatetime(md.kickoff_date)} ist ungewöhnlich`);
          }
        }

        // Transfer windows: end must be 20:00 (any day) or Fri 15:00
        for (const tw of tws) {
          const dt = new Date(tw.end_date.replace(' ', 'T'));
          const day = dt.getDay();
          const h = dt.getHours();
          const min = dt.getMinutes();
          const md = matchdayMap.get(tw.matchday_id);
          const isMatchday1 = md?.number === 1;
          const valid =
            (h === 20 && min === 0) ||
            (day === 5 && h === 15 && min === 0) ||
            (day === 5 && h === 12 && min === 0) ||
            (isMatchday1 && h === 18 && min === 0);
          if (!valid) {
            const sName = md ? (seasonMap.get(md.season_id) ?? '') : '';
            const label = md ? `Spieltag ${md.number} (${sName})` : tw.matchday_id;
            warnings.push(`TF ${label}: Ende ${this.formatDatetime(tw.end_date)} ist ungewöhnlich`);
          }
        }

        // Transfer windows: no overlaps
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

  private readonly DEFAULT_KICKOFF_TIME = '20:30';

  private combineDateTime(date: string, time: string): string {
    return `${date} ${time}:00`;
  }

  private kickoffTime(kickoffDate: string): string {
    return kickoffDate.substring(11, 16);
  }

  private formatDateYMD(d: Date): string {
    const y   = d.getFullYear();
    const m   = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  private nextFriday(dateStr: string): string {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + (5 - d.getDay() + 7) % 7);
    return this.formatDateYMD(d);
  }

  // Next Tuesday strictly after the given date (or datetime) — used to default a new
  // matchday's start_date to the Tuesday following the previous matchday's kickoff.
  private nextTuesdayAfter(dateStr: string): string {
    const d = new Date(dateStr.substring(0, 10) + 'T00:00:00');
    d.setDate(d.getDate() + 1);
    d.setDate(d.getDate() + (2 - d.getDay() + 7) % 7);
    return this.formatDateYMD(d);
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

  private toDatetimeLocal(mysqlDt: string): string {
    return mysqlDt.replace(' ', 'T').substring(0, 16);
  }

  editingTwId = signal<string | null>(null);
  editTwStart = signal('');
  editTwEnd   = signal('');
  editTwState = signal<'idle' | 'loading' | 'error'>('idle');
  editTwError = signal('');

  startEditTw(tw: Transferwindow): void {
    this.editingTwId.set(tw.id);
    this.editTwStart.set(this.toDatetimeLocal(tw.start_date));
    this.editTwEnd.set(this.toDatetimeLocal(tw.end_date));
    this.editTwState.set('idle');
    this.editTwError.set('');
  }

  cancelEditTw(): void {
    this.editingTwId.set(null);
  }

  submitEditTw(tw: Transferwindow): void {
    if (this.editTwStart() >= this.editTwEnd()) {
      this.editTwState.set('error');
      this.editTwError.set('Start muss vor Ende liegen');
      return;
    }
    this.editTwState.set('loading');
    this.api.patch<any>(`transferwindow/${tw.id}`, {
      start_date: this.toMysqlDatetime(this.editTwStart()),
      end_date:   this.toMysqlDatetime(this.editTwEnd()),
    }).subscribe({
      next: () => {
        this.editingTwId.set(null);
        this.editTwState.set('idle');
        this.detailReload$.next();
      },
      error: (err) => {
        this.editTwState.set('error');
        this.editTwError.set(err?.error?.message ?? 'Fehler beim Speichern');
      },
    });
  }

  deletingTwId  = signal<string | null>(null);
  twDeleteError = signal<string | null>(null);

  deleteTw(tw: Transferwindow, label: string): void {
    if (!confirm(`${label} wirklich löschen?`)) return;

    this.deletingTwId.set(tw.id);
    this.twDeleteError.set(null);
    this.api.delete<any>(`transferwindow/${tw.id}`).subscribe({
      next: () => {
        this.deletingTwId.set(null);
        this.detailReload$.next();
      },
      error: (err) => {
        this.deletingTwId.set(null);
        this.twDeleteError.set(err?.error?.message ?? 'Fehler beim Löschen');
      },
    });
  }

  creatingMatchday           = signal(false);
  createMdNumber             = signal<number | null>(null);
  createMdStart              = signal('');
  createMdKickoffDate        = signal('');
  createMdKickoffDateTouched = signal(false);
  createMdKickoffTime        = signal(this.DEFAULT_KICKOFF_TIME);
  createMdState              = signal<'idle' | 'loading' | 'error'>('idle');
  createMdError              = signal('');

  onCreateMdStartChange(value: string): void {
    this.createMdStart.set(value);
    if (!this.createMdKickoffDateTouched() && value) {
      this.createMdKickoffDate.set(this.nextFriday(value));
    }
  }

  onCreateMdKickoffDateChange(value: string): void {
    this.createMdKickoffDate.set(value);
    this.createMdKickoffDateTouched.set(true);
  }

  openCreateMatchday(): void {
    const lastMatchday = this.matchdays()[0];
    const nextNumber   = (lastMatchday?.number ?? 0) + 1;
    const defaultStart = lastMatchday
      ? this.nextTuesdayAfter(lastMatchday.kickoff_date)
      : (this.selectedSeason()?.start_date ?? '');

    this.creatingMatchday.set(true);
    this.createMdNumber.set(nextNumber);
    this.createMdKickoffDate.set('');
    this.createMdKickoffDateTouched.set(false);
    this.onCreateMdStartChange(defaultStart);
    this.createMdKickoffTime.set(this.DEFAULT_KICKOFF_TIME);
    this.createMdState.set('idle');
    this.createMdError.set('');
  }

  cancelCreateMatchday(): void {
    this.creatingMatchday.set(false);
  }

  submitCreateMatchday(): void {
    const season = this.selectedSeason();
    const number = this.createMdNumber();
    if (!season || !number || !this.createMdStart() || !this.createMdKickoffDate() || !this.createMdKickoffTime()) return;

    this.createMdState.set('loading');
    this.api.post<any>('matchday', {
      season_id:    season.id,
      number,
      start_date:   this.createMdStart(),
      kickoff_date: this.combineDateTime(this.createMdKickoffDate(), this.createMdKickoffTime()),
    }).subscribe({
      next: () => {
        this.creatingMatchday.set(false);
        this.createMdState.set('idle');
        this.detailReload$.next();
      },
      error: (err) => {
        this.createMdState.set('error');
        this.createMdError.set(err?.error?.message ?? 'Fehler beim Erstellen');
      },
    });
  }

  editingMatchdayId = signal<string | null>(null);
  editMdNumber      = signal<number | null>(null);
  editMdStart       = signal('');
  editMdKickoffDate = signal('');
  editMdKickoffTime = signal(this.DEFAULT_KICKOFF_TIME);
  editMdState       = signal<'idle' | 'loading' | 'error'>('idle');
  editMdError       = signal('');

  startEditMatchday(matchday: Matchday): void {
    this.editingMatchdayId.set(matchday.id);
    this.editMdNumber.set(matchday.number);
    this.editMdStart.set(matchday.start_date);
    this.editMdKickoffDate.set(matchday.kickoff_date.substring(0, 10));
    this.editMdKickoffTime.set(this.kickoffTime(matchday.kickoff_date));
    this.editMdState.set('idle');
    this.editMdError.set('');
  }

  cancelEditMatchday(): void {
    this.editingMatchdayId.set(null);
  }

  submitEditMatchday(matchday: Matchday): void {
    const number = this.editMdNumber();
    if (!number || !this.editMdStart() || !this.editMdKickoffDate() || !this.editMdKickoffTime()) return;

    this.editMdState.set('loading');
    this.api.patch<any>(`matchday/${matchday.id}`, {
      number,
      start_date:   this.editMdStart(),
      kickoff_date: this.combineDateTime(this.editMdKickoffDate(), this.editMdKickoffTime()),
    }).subscribe({
      next: () => {
        this.editingMatchdayId.set(null);
        this.editMdState.set('idle');
        this.detailReload$.next();
      },
      error: (err) => {
        this.editMdState.set('error');
        this.editMdError.set(err?.error?.message ?? 'Fehler beim Speichern');
      },
    });
  }

  deletingMatchdayId  = signal<string | null>(null);
  matchdayDeleteError = signal<string | null>(null);

  deleteMatchday(matchday: Matchday): void {
    if (!confirm(`Spieltag ${matchday.number} wirklich löschen?`)) return;

    this.deletingMatchdayId.set(matchday.id);
    this.matchdayDeleteError.set(null);
    this.api.delete<any>(`matchday/${matchday.id}`).subscribe({
      next: () => {
        this.deletingMatchdayId.set(null);
        if (this.selectedMatchday()?.id === matchday.id) this.selectedMatchday.set(null);
        this.detailReload$.next();
      },
      error: (err) => {
        this.deletingMatchdayId.set(null);
        this.matchdayDeleteError.set(err?.error?.message ?? 'Fehler beim Löschen');
      },
    });
  }

  createSeasonOpen  = signal(false);
  createSeasonDate  = signal('');
  createSeasonState = signal<'idle' | 'loading' | 'error'>('idle');
  createSeasonError = signal('');

  openCreateSeason(): void {
    this.createSeasonOpen.set(true);
    this.createSeasonDate.set('');
    this.createSeasonState.set('idle');
    this.createSeasonError.set('');
  }

  cancelCreateSeason(): void {
    this.createSeasonOpen.set(false);
  }

  submitCreateSeason(): void {
    const date = this.createSeasonDate();
    if (!date) return;
    this.createSeasonState.set('loading');
    this.api.post<any>('season', { start_date: date }).subscribe({
      next: () => {
        this.createSeasonOpen.set(false);
        this.createSeasonState.set('idle');
        this.reload$.next();
      },
      error: (err) => {
        this.createSeasonState.set('error');
        this.createSeasonError.set(err?.error?.message ?? 'Fehler beim Erstellen');
      },
    });
  }
}
