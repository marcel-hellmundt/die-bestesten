import { Component, HostListener, computed, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, distinctUntilChanged, filter, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { AuthService } from '../../auth/auth.service';
import { TeamNavService } from '../../core/team-nav.service';

@Component({
  selector: 'app-team-detail',
  standalone: false,
  templateUrl: './team-detail.component.html',
  styleUrl: './team-detail.component.scss'
})
export class TeamDetailComponent {
  private api     = inject(ApiService);
  private auth    = inject(AuthService);
  private router  = inject(Router);
  private teamNav = inject(TeamNavService);
  cache           = inject(DataCacheService);

  private id$ = inject(ActivatedRoute).paramMap.pipe(map(p => p.get('id')!));
  private currentTeamId = toSignal(this.id$, { initialValue: '' });

  // Liste vom navigierenden Absender (Manager-Seite/Liga-Tabelle/Spieltag) gesetzt — nur gültig,
  // wenn die aktuelle Team-ID tatsächlich darin vorkommt (sonst z.B. Direktaufruf/fremder Link).
  // Fallback für alle Einstiege ohne eigene Liste (Ruhmeshalle, Markt, Spielerseite, Suche,
  // Direktaufruf, ...): alle Teams derselben Saison wie das aktuell geöffnete Team.
  private seasonTeamIds = toSignal(
    toObservable(computed(() => this.team()?.season_id as string | undefined)).pipe(
      filter((seasonId): seasonId is string => !!seasonId),
      distinctUntilChanged(),
      switchMap(seasonId =>
        this.api.get<any[]>(`team?season_id=${seasonId}`).pipe(
          map(teams => teams.map(t => t.id as string)),
          catchError(() => of([] as string[]))
        )
      )
    ),
    { initialValue: [] as string[] }
  );

  private navList = computed<string[] | null>(() => {
    const current = this.currentTeamId();
    const ids = this.teamNav.getContext();
    if (ids && ids.includes(current)) return ids;
    const fallback = this.seasonTeamIds();
    return fallback.includes(current) ? fallback : null;
  });

  private navIndex = computed(() => {
    const list = this.navList();
    return list ? list.indexOf(this.currentTeamId()) : -1;
  });

  hasTeamNav = computed(() => this.navList() !== null);
  canGoPrevTeam = computed(() => this.navIndex() > 0);
  canGoNextTeam = computed(() => {
    const list = this.navList();
    return list !== null && this.navIndex() < list.length - 1;
  });

  private readonly TAB_SEGMENTS = ['uebersicht', 'kader', 'aufstellung', 'finanzen'];

  private currentTabSegment(): string | null {
    const last = this.router.url.split('?')[0].split('/').pop() ?? '';
    return this.TAB_SEGMENTS.includes(last) ? last : null;
  }

  private goToTeamOffset(offset: number): void {
    const list = this.navList();
    if (!list) return;
    const targetId = list[this.navIndex() + offset];
    if (!targetId) return;
    const tab = this.currentTabSegment();
    this.router.navigate(tab ? ['/team', targetId, tab] : ['/team', targetId]);
  }

  goPrevTeam(): void { this.goToTeamOffset(-1); }
  goNextTeam(): void { this.goToTeamOffset(1); }

  /** Desktop: Pfeiltasten ←/→ wie die beiden Pfeil-Buttons neben dem Teamnamen. */
  @HostListener('document:keydown', ['$event'])
  onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    // Nicht beim Tippen in Feldern (Cursor bewegen) oder in eigenen Tastatur-Widgets
    const el = event.target as HTMLElement | null;
    if (el && (el.isContentEditable || el.closest('input, textarea, select, [role="slider"], [role="listbox"]'))) return;

    if (event.key === 'ArrowLeft' && this.canGoPrevTeam()) {
      event.preventDefault();
      this.goPrevTeam();
    } else if (event.key === 'ArrowRight' && this.canGoNextTeam()) {
      event.preventDefault();
      this.goNextTeam();
    }
  }

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`team/${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    )
  );

  team      = computed(() => this.state()?.data ?? null);
  loading   = computed(() => this.state()?.loading ?? true);
  error     = computed(() => this.state()?.error ?? null);
  isOwnTeam = computed(() => this.team()?.manager_id === this.auth.getManagerId());

  private readonly SQUAD_MIN: Record<string, number> = {
    GOALKEEPER: 1, DEFENDER: 5, MIDFIELDER: 5, FORWARD: 3,
  };

  private squad = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any[]>(`player_in_team?team_id=${id}`).pipe(
          catchError(() => of([] as any[]))
        )
      )
    ),
    { initialValue: [] as any[] }
  );

  squadInvalid = computed(() => {
    const counts: Record<string, number> = {};
    for (const p of this.squad()) {
      if (p.position) counts[p.position] = (counts[p.position] ?? 0) + 1;
    }
    return Object.entries(this.SQUAD_MIN).some(([pos, min]) => (counts[pos] ?? 0) < min);
  });

  // Same 7 formations the lineup editor allows — kept in sync with lineup.component.ts.
  private readonly VALID_FORMATIONS = [
    [1,3,4,3],[1,3,5,2],[1,4,3,3],[1,4,4,2],[1,4,5,1],[1,5,3,2],[1,5,4,1],
  ];
  private readonly POS_INDEX: Record<string, number> = {
    GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3,
  };

  private lineup = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`team_lineup?team_id=${id}`).pipe(
          catchError(() => of(null as any))
        )
      )
    ),
    { initialValue: null as any }
  );

  lineupInvalid = computed(() => {
    const data = this.lineup();
    if (!data?.matchday) return true;
    const counts = [0, 0, 0, 0];
    for (const p of (data.nominated ?? []) as any[]) {
      const i = this.POS_INDEX[p.position];
      if (i !== undefined) counts[i]++;
    }
    return !this.VALID_FORMATIONS.some(f => f.every((v, i) => v === counts[i]));
  });

  logoFailed = false;

  constructor() {
    this.cache.ensureSeasons();
  }
}
