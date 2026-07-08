import { Component, inject, signal, computed } from '@angular/core';
import { Router } from '@angular/router';
import { forkJoin, of, combineLatest } from 'rxjs';
import { filter, take, switchMap, map, catchError } from 'rxjs/operators';
import { toObservable } from '@angular/core/rxjs-interop';
import { DataCacheService } from '../core/data-cache.service';
import { ApiService } from '../core/api.service';
import { Matchday } from '../core/models/matchday.model';
import { environment } from '../../environments/environment';

@Component({
  selector: 'app-home',
  standalone: false,
  templateUrl: './home.component.html',
  styleUrl: './home.component.scss',
})
export class HomeComponent {
  cache  = inject(DataCacheService);
  router = inject(Router);
  private api = inject(ApiService);

  matchdays        = signal<Matchday[]>([]);
  windows          = signal<any[]>([]);
  h2hData          = signal<any | null>(null);
  standings        = signal<any[]>([]);
  budget           = signal<number | null>(null);
  loading          = signal(true);
  currentSeasonId  = signal<string | null>(null);
  lastMatchday     = signal<any | null>(null);
  lineup           = signal<any | null>(null);

  private logoErrors = new Set<string>();
  teamLogoUrl(teamId: string): string {
    return `${environment.imageApiUrl}/team/${this.currentSeasonId()}/${teamId}.png`;
  }
  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  // ── Computed ─────────────────────────────────────────────────────────────────

  currentMatchday = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return [...this.matchdays()]
      .sort((a, b) => a.number - b.number)
      .filter(m => m.start_date <= today)
      .at(-1) ?? null;
  });

  matchdayStatus = computed((): 'transfer' | 'lineup' | 'live' | 'completed' | null => {
    const md = this.currentMatchday();
    if (!md) return null;
    const now = new Date();
    const openWindow = this.windows().find(w =>
      new Date(w.start_date) <= now && new Date(w.end_date) >= now && w.matchday_id === md.id
    );
    if (openWindow) return 'transfer';
    if (md.completed) return 'completed';
    if (new Date(md.kickoff_date) > now) return 'lineup';
    return 'live';
  });

  transferWindowEnd = computed(() => {
    const md = this.currentMatchday();
    if (!md) return null;
    const now = new Date();
    return this.windows().find(w =>
      new Date(w.start_date) <= now && new Date(w.end_date) >= now && w.matchday_id === md.id
    )?.end_date ?? null;
  });

  h2hPhase = computed((): string | null => {
    const md = this.currentMatchday();
    if (!md || !this.h2hData()) return null;
    const ko = (this.h2hData()!.knockout_matches ?? []) as any[];
    const koForDay = ko.filter(m => m.matchday_number === md.number);
    if (koForDay.length > 0) {
      const labels: Record<string, string> = { quarterfinal: 'Viertelfinale', semifinal: 'Halbfinale', final: 'Finale' };
      return labels[koForDay[0].phase] ?? koForDay[0].phase;
    }
    const groups = (this.h2hData()!.groups ?? []) as any[];
    const groupForDay = groups.flatMap((g: any) => g.matches ?? []).filter((m: any) => m.matchday_number === md.number);
    return groupForDay.length > 0 ? 'Gruppenphase' : null;
  });

  h2hMatchesForDay = computed(() => {
    const md = this.currentMatchday();
    if (!md || !this.h2hData()) return [];
    const all: any[] = [
      ...(this.h2hData()!.groups?.flatMap((g: any) => g.matches ?? []) ?? []),
      ...(this.h2hData()!.knockout_matches ?? []),
    ];
    return all.filter(m => m.matchday_number === md.number);
  });

  nextH2hMatch = computed(() => {
    const myId = this.cache.myTeamId();
    const md = this.currentMatchday();
    if (!myId || !md || !this.h2hData()) return null;
    const all: any[] = [
      ...(this.h2hData()!.groups?.flatMap((g: any) => g.matches ?? []) ?? []),
      ...(this.h2hData()!.knockout_matches ?? []),
    ];
    const next = all
      .filter(m => (m.home_team_id === myId || m.away_team_id === myId) && m.matchday_number > md.number)
      .sort((a, b) => a.matchday_number - b.matchday_number)[0];
    if (!next) return null;
    const isHome = next.home_team_id === myId;
    return {
      matchday: this.matchdays().find(m => m.number === next.matchday_number) ?? null,
      opponentId:    isHome ? next.away_team_id   : next.home_team_id,
      opponentName:  isHome ? next.away_team_name : next.home_team_name,
      opponentColor: isHome ? next.away_color      : next.home_color,
    };
  });

  miniTable = computed(() => {
    const rows = this.standings();
    const myId = this.cache.myTeamId();
    if (!rows.length) return [];
    if (!myId) return rows.slice(0, 3).map((r, i) => ({ ...r, rank: i + 1 }));
    const idx = rows.findIndex(r => r.team_id === myId);
    const start = idx === -1 ? 0 : Math.max(0, Math.min(idx - 1, rows.length - 3));
    return rows.slice(start, start + 3).map((r, i) => ({ ...r, rank: start + i + 1 }));
  });

  myLastMatchdayRating = computed(() => {
    const myId = this.cache.myTeamId();
    const data = this.lastMatchday();
    if (!myId || !data?.ratings) return null;
    return (data.ratings as any[]).find(r => r.team_id === myId) ?? null;
  });

  myLastMatchdayRank = computed(() => {
    const myId = this.cache.myTeamId();
    const data = this.lastMatchday();
    if (!myId || !data?.ratings) return null;
    const sorted = [...(data.ratings as any[])].sort((a, b) => b.points - a.points);
    const idx = sorted.findIndex(r => r.team_id === myId);
    return idx >= 0 ? idx + 1 : null;
  });

  nextTransferWindowInfo = computed(() => {
    const now = new Date();
    const open = this.windows().find(w => new Date(w.start_date) <= now && new Date(w.end_date) >= now);
    if (open) return { status: 'open' as const, date: open.end_date };
    const next = [...this.windows()]
      .filter(w => new Date(w.start_date) > now)
      .sort((a, b) => new Date(a.start_date).getTime() - new Date(b.start_date).getTime())[0];
    return next ? { status: 'upcoming' as const, date: next.start_date } : null;
  });

  nominatedCount = computed(() => {
    const l = this.lineup();
    return l?.nominated ? (l.nominated as any[]).length : null;
  });

  relativeTime(isoDate: string): string {
    const diff = new Date(isoDate).getTime() - Date.now();
    if (diff <= 0) return '';
    const hours = Math.floor(diff / 3_600_000);
    if (hours < 24) return `noch ${hours} Std.`;
    const days = Math.floor(diff / 86_400_000);
    return `in ${days} Tag${days === 1 ? '' : 'en'}`;
  }

  // ── Constructor ──────────────────────────────────────────────────────────────

  constructor() {
    this.cache.ensureSeasons();
    this.cache.ensureMyTeam();
    this.cache.ensureSquad();

    toObservable(this.cache.startedSeasons).pipe(
      filter(s => s.length > 0),
      take(1),
      switchMap(seasons => {
        const seasonId = [...seasons].sort((a, b) => b.start_date.localeCompare(a.start_date))[0].id;
        return forkJoin({
          matchdays: this.api.get<any[]>(`matchday?season_id=${seasonId}`).pipe(
            map(d => d.map(Matchday.from)),
            catchError(() => of([] as Matchday[]))
          ),
          windows: this.api.get<any[]>(`transferwindow?season_id=${seasonId}`).pipe(
            catchError(() => of([] as any[]))
          ),
          h2h: this.api.get<any>(`h2h?season_id=${seasonId}`).pipe(
            catchError(() => of(null))
          ),
          standings: this.api.get<any>(`team_rating/season?season_id=${seasonId}`).pipe(
            map(d => d.standings ?? []),
            catchError(() => of([] as any[]))
          ),
          lastMatchday: this.api.get<any>(`team_rating?season_id=${seasonId}`).pipe(
            catchError(() => of(null))
          ),
        }).pipe(map(r => ({ ...r, seasonId })));
      })
    ).subscribe(r => {
      this.currentSeasonId.set(r.seasonId);
      this.matchdays.set(r.matchdays);
      this.windows.set(r.windows);
      this.h2hData.set(r.h2h);
      this.standings.set(r.standings);
      this.lastMatchday.set(r.lastMatchday);
      this.loading.set(false);
    });

    toObservable(this.cache.myTeamId).pipe(
      filter(id => !!id),
      take(1),
      switchMap(teamId =>
        this.api.get<any>(`transaction?team_id=${teamId}`).pipe(
          map(d => d.budget ?? null),
          catchError(() => of(null))
        )
      )
    ).subscribe(budget => this.budget.set(budget));

    combineLatest([
      toObservable(this.cache.myTeamId).pipe(filter(id => !!id)),
      toObservable(this.currentMatchday).pipe(filter(md => !!md)),
    ]).pipe(
      take(1),
      switchMap(([teamId, md]) =>
        this.api.get<any>(`team_lineup?team_id=${teamId}&matchday_id=${md!.id}`).pipe(
          catchError(() => of(null))
        )
      )
    ).subscribe(l => this.lineup.set(l));
  }
}
