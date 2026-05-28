import { Component, inject, signal, computed } from '@angular/core';
import { Router } from '@angular/router';
import { forkJoin, of } from 'rxjs';
import { filter, take, switchMap, map, catchError } from 'rxjs/operators';
import { toObservable } from '@angular/core/rxjs-interop';
import { DataCacheService } from '../core/data-cache.service';
import { ApiService } from '../core/api.service';
import { Matchday } from '../core/models/matchday.model';

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

  matchdays = signal<Matchday[]>([]);
  windows   = signal<any[]>([]);
  h2hData   = signal<any | null>(null);
  standings = signal<any[]>([]);
  budget    = signal<number | null>(null);
  loading   = signal(true);

  currentMatchday = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return this.matchdays().filter(m => m.start_date <= today).at(-1) ?? null;
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

  h2hMatchesForDay = computed(() => {
    const md = this.currentMatchday();
    if (!md || !this.h2hData()) return [];
    const all: any[] = [
      ...(this.h2hData()!.groups?.flatMap((g: any) => g.matches ?? []) ?? []),
      ...(this.h2hData()!.knockout_matches ?? []),
    ];
    return all.filter(m => m.matchday_number === md.number);
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

  constructor() {
    this.cache.ensureSeasons();
    this.cache.ensureMyTeam();
    this.cache.ensureSquad();

    toObservable(this.cache.seasons).pipe(
      filter(s => s.length > 0),
      take(1),
      switchMap(seasons => {
        const seasonId = seasons[0].id;
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
        });
      })
    ).subscribe(r => {
      this.matchdays.set(r.matchdays);
      this.windows.set(r.windows);
      this.h2hData.set(r.h2h);
      this.standings.set(r.standings);
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
  }
}
