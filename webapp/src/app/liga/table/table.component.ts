import { Component, ElementRef, ViewChild, computed, effect, inject, signal } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, filter, map, of, scan, startWith, switchMap } from 'rxjs';
import { Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { AuthService } from '../../auth/auth.service';
import { Matchday } from '../../core/models/matchday.model';
import { TeamNavService } from '../../core/team-nav.service';

@Component({
  selector: 'app-liga-table',
  standalone: false,
  templateUrl: './table.component.html',
  styleUrl: './table.component.scss'
})
export class TableComponent {
  private api    = inject(ApiService);
  private auth   = inject(AuthService);
  private router = inject(Router);
  private teamNav = inject(TeamNavService);
  cache        = inject(DataCacheService);

  isLoggedIn = computed(() => this.auth.isLoggedIn());

  // Seasons sorted newest first (future seasons excluded)
  seasons = computed(() =>
    [...this.cache.startedSeasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))
  );

  selectedIndex = signal(0);

  selectedSeason = computed(() => this.seasons()[this.selectedIndex()] ?? null);

  canDecrement = computed(() => this.selectedIndex() < this.seasons().length - 1);
  canIncrement = computed(() => this.selectedIndex() > 0);

  decrement() { if (this.canDecrement()) this.selectedIndex.update(i => i + 1); }
  increment() { if (this.canIncrement()) this.selectedIndex.update(i => i - 1); }

  onSeasonChange(id: string): void {
    const idx = this.seasons().findIndex(s => s.id === id);
    if (idx >= 0) this.selectedIndex.set(idx);
  }

  // Zeitraum-Filter ("Zeitraum"-Toggle auf /liga/tabelle): schränkt die Saisonauswertung auf
  // Spieltag fromMatchday..toMatchday ein (beide inklusiv), siehe GET /team_rating/season's
  // from_matchday_number/to_matchday_number. Default: kein Filter (ganze Saison, unverändertes
  // Verhalten) — intervalMode aus, from/to null.
  intervalMode  = signal(false);
  fromMatchday  = signal<number | null>(null);
  toMatchday    = signal<number | null>(null);

  // Abgeschlossene Spieltage der aktuell gewählten Saison — Grundlage für die Zeitraum-
  // Dropdown-Optionen und deren Min/Max.
  private matchdaysState = toSignal(
    toObservable(this.selectedSeason).pipe(
      filter((s): s is NonNullable<typeof s> => !!s),
      switchMap(season =>
        this.api.get<any[]>(`matchday?season_id=${season.id}`).pipe(
          map(data => (data ?? [])
            .map(Matchday.from)
            .filter(m => m.completed)
            .sort((a, b) => a.number - b.number)),
          catchError(() => of([] as Matchday[]))
        )
      )
    ),
    { initialValue: [] as Matchday[] }
  );

  completedMatchdayNumbers = computed(() => this.matchdaysState().map(m => m.number));
  minMatchdayNumber = computed(() => {
    const nums = this.completedMatchdayNumbers();
    return nums.length ? nums[0] : null;
  });
  maxMatchdayNumber = computed(() => {
    const nums = this.completedMatchdayNumbers();
    return nums.length ? nums[nums.length - 1] : null;
  });

  // Zeitraum bleibt beim Saisonwechsel aktiv (statt sich zurückzusetzen) — nur die Auswahl wird
  // ggf. an die neue Saison angepasst: keine abgeschlossenen Spieltage (oder nur einer) schaltet
  // den Zeitraum-Modus ab (der Button selbst wird dann ohnehin ausgeblendet, siehe Template),
  // sonst wird from/to auf die neue Spieltags-Range geklemmt (z.B. beim Wechsel zur aktuellen
  // Saison, deren letzter abgeschlossener Spieltag niedriger liegen kann als das zuvor gewählte
  // "bis").
  private syncIntervalRangeEffect = effect(() => {
    const nums = this.completedMatchdayNumbers();
    if (!this.intervalMode()) return;
    if (nums.length <= 1) {
      this.intervalMode.set(false);
      this.fromMatchday.set(null);
      this.toMatchday.set(null);
      return;
    }
    const min = nums[0];
    const max = nums[nums.length - 1];
    const clamp = (n: number | null) => n === null ? null : Math.min(Math.max(n, min), max);
    const from = clamp(this.fromMatchday()) ?? min;
    const to   = clamp(this.toMatchday())   ?? max;
    if (from !== this.fromMatchday()) this.fromMatchday.set(from);
    if (to   !== this.toMatchday())   this.toMatchday.set(to);
  });

  toggleIntervalMode(): void {
    const next = !this.intervalMode();
    this.intervalMode.set(next);
    if (next) {
      // Zeitraum + Live schließen sich aus (laufender Spieltag + fester Endpunkt in der
      // Vergangenheit wäre widersprüchlich) — siehe Live-Button-Guard im Template.
      this.liveMode.set(false);
      // Vorausgefüllt mit der kompletten Saison (Spieltag 1 bis zuletzt abgeschlossen) — Nutzer
      // schränkt von dort aus gezielt ein, statt bei einer leeren/unerwarteten Auswahl zu starten.
      this.fromMatchday.set(1);
      this.toMatchday.set(this.maxMatchdayNumber());
    } else {
      this.fromMatchday.set(null);
      this.toMatchday.set(null);
    }
  }

  private clampMatchday(n: number): number {
    const min = this.minMatchdayNumber() ?? 1;
    const max = this.maxMatchdayNumber() ?? min;
    if (!Number.isFinite(n)) return min;
    return Math.min(Math.max(n, min), max);
  }

  onFromMatchdayChange(value: string): void {
    const n = this.clampMatchday(Number(value));
    this.fromMatchday.set(n);
    if (this.toMatchday() !== null && n > this.toMatchday()!) this.toMatchday.set(n);
  }

  onToMatchdayChange(value: string): void {
    const n = this.clampMatchday(Number(value));
    this.toMatchday.set(n);
    if (this.fromMatchday() !== null && n < this.fromMatchday()!) this.fromMatchday.set(n);
  }

  // keepPrevious markiert die sofortige "gerade erst losgeschickt"-Zwischen-Emission des inneren
  // switchMap — der nachfolgende scan() ersetzt dort data NICHT durch null, sondern behält den
  // zuletzt geladenen Stand, damit z.B. das Umschalten des Zeitraum-Toggles nicht die komplette
  // Seite kurz auf "Laden…" zurücksetzt (sichtbares weißes Aufblitzen) — nur ein echtes neues
  // Ergebnis (Erfolg oder Fehler) ersetzt die angezeigten Daten.
  private state = toSignal(
    combineLatest([
      toObservable(this.seasons).pipe(filter(s => s.length > 0)),
      toObservable(this.selectedIndex),
      toObservable(this.intervalMode),
      toObservable(this.fromMatchday),
      toObservable(this.toMatchday),
    ]).pipe(
      switchMap(([seasons, idx, interval, from, to]) => {
        const season = seasons[idx];
        if (!season) return of({ data: null as any, loading: false, error: null as string | null, keepPrevious: false });
        let url = `team_rating/season?season_id=${season.id}`;
        if (interval && from !== null && to !== null) {
          url += `&from_matchday_number=${from}&to_matchday_number=${to}`;
        }
        return this.api.get<any>(url).pipe(
          map(data => ({ data, loading: false, error: null as string | null, keepPrevious: false })),
          startWith({ data: null as any, loading: true, error: null as string | null, keepPrevious: true }),
          catchError(() => of({ data: null as any, loading: false, error: 'Fehler beim Laden', keepPrevious: false }))
        );
      }),
      scan(
        (prev, curr) => curr.keepPrevious
          ? { data: prev.data, loading: true, error: null as string | null }
          : { data: curr.data, loading: curr.loading, error: curr.error },
        { data: null as any, loading: true, error: null as string | null }
      )
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  // Nur beim allerersten Laden (noch keine Daten vorhanden) den ganzen Inhalt durch "Laden…"
  // ersetzen — ein Refetch mit bereits vorhandenen Daten (Zeitraum-/Live-Toggle, Saisonwechsel)
  // soll die bestehende Ansicht nicht kurz verschwinden lassen.
  showInitialLoading = computed(() => this.loading() && !this.state().data);

  isCurrentSeason = computed(() => this.selectedIndex() === 0);

  liveMode = signal(false);

  // Live data for current (not-completed) matchday
  private liveState = toSignal(
    combineLatest([
      toObservable(this.selectedSeason),
      toObservable(this.liveMode),
    ]).pipe(
      switchMap(([season, live]) => {
        if (!live || !season) return of(null);
        return this.api.get<any>(`team_rating?season_id=${season.id}`).pipe(
          catchError(() => of(null))
        );
      })
    ),
    { initialValue: null as any }
  );

  private baseRows = computed(() =>
    (this.state().data?.standings ?? []).map((r: any) => ({
      ...r,
      total_points:            Number(r.total_points)            || 0,
      total_goals:             Number(r.total_goals)              || 0,
      total_assists:           Number(r.total_assists)            || 0,
      total_sds:               Number(r.total_sds)                || 0,
      total_clean_sheet:       Number(r.total_clean_sheet)        || 0,
      total_red_cards:         Number(r.total_red_cards)          || 0,
      total_yellow_red_cards:  Number(r.total_yellow_red_cards)   || 0,
      total_points_goalkeeper: Number(r.total_points_goalkeeper)  || 0,
      total_points_defender:   Number(r.total_points_defender)    || 0,
      total_points_midfielder: Number(r.total_points_midfielder)  || 0,
      total_points_forward:    Number(r.total_points_forward)     || 0,
    })) as any[]
  );

  rows = computed(() => {
    const base = this.baseRows();
    if (!this.liveMode()) return base;
    const live = this.liveState();
    if (!live?.ratings?.length || live.matchday?.completed) return base;
    const liveMap = new Map<string, any>();
    for (const r of live.ratings as any[]) liveMap.set(r.team_id, r);
    const n = (v: any) => Number(v) || 0;
    return [...base]
      .map(r => {
        const lr = liveMap.get(r.team_id);
        if (!lr) return r;
        return {
          ...r,
          total_points:           n(r.total_points)           + n(lr.points),
          total_goals:            n(r.total_goals)            + n(lr.goals),
          total_assists:          n(r.total_assists)          + n(lr.assists),
          total_sds:              n(r.total_sds)              + n(lr.sds),
          total_clean_sheet:      n(r.total_clean_sheet)      + n(lr.clean_sheet),
          total_red_cards:        n(r.total_red_cards)        + n(lr.red_cards),
          total_yellow_red_cards: n(r.total_yellow_red_cards) + n(lr.yellow_red_cards),
        };
      })
      .sort((a, b) => b.total_points - a.total_points);
  });

  totalFines     = computed(() => this.rows().reduce((sum, r) => sum + Number(r.fine ?? 0), 0));

  // Rang (#) bleibt immer der Punkte-Rang (mit geteilten Plätzen bei Gleichstand) — unabhängig
  // davon, nach welcher Spalte die Tabelle gerade sortiert angezeigt wird.
  private rankByTeam = computed(() => {
    const base = this.rows();
    const map = new Map<string, number>();
    base.forEach((r, i) => {
      const rank = i > 0 && r.total_points === base[i - 1].total_points
        ? map.get(base[i - 1].team_id)!
        : i + 1;
      map.set(r.team_id, rank);
    });
    return map;
  });

  rankOf(teamId: string): number {
    return this.rankByTeam().get(teamId) ?? 0;
  }

  sortCol = signal<'points' | 'sds' | 'goals' | 'assists' | 'yellow_red' | 'red' | 'clean_sheet' | 'fine'>('points');
  sortDir = signal<'asc' | 'desc'>('desc');

  sortedRows = computed(() => {
    const col = this.sortCol();
    const dir = this.sortDir();
    const list = [...this.rows()];
    list.sort((a, b) => {
      let cmp: number;
      switch (col) {
        case 'points':      cmp = a.total_points - b.total_points; break;
        case 'sds':         cmp = a.total_sds - b.total_sds; break;
        case 'goals':       cmp = a.total_goals - b.total_goals; break;
        case 'assists':     cmp = a.total_assists - b.total_assists; break;
        case 'yellow_red':  cmp = a.total_yellow_red_cards - b.total_yellow_red_cards; break;
        case 'red':         cmp = a.total_red_cards - b.total_red_cards; break;
        case 'clean_sheet': cmp = a.total_clean_sheet - b.total_clean_sheet; break;
        case 'fine':        cmp = Number(a.fine ?? 0) - Number(b.fine ?? 0); break;
      }
      return dir === 'asc' ? cmp : -cmp;
    });
    return list;
  });

  sort(col: 'points' | 'sds' | 'goals' | 'assists' | 'yellow_red' | 'red' | 'clean_sheet' | 'fine'): void {
    if (this.sortCol() === col) {
      this.sortDir.update(d => d === 'asc' ? 'desc' : 'asc');
    } else {
      this.sortCol.set(col);
      this.sortDir.set('desc');
    }
  }

  // Startgeld ist fest in team_rating.database.php eingepreist (fine = Summe der Spieltagsstrafen
  // + 5.0 €) — entspricht die Gesamtstrafe genau diesem Betrag, ist bislang keine echte
  // Spieltagsstrafe dazugekommen und die Anzeige soll entsprechend zurückhaltender wirken.
  private readonly STARTGELD = 5;
  fineIsStartgeldOnly(r: any): boolean {
    return Number(r.fine ?? 0) === this.STARTGELD;
  }
  lucky          = computed(() => (this.state().data?.luck?.lucky           ?? []) as any[]);
  unlucky        = computed(() => (this.state().data?.luck?.unlucky          ?? []) as any[]);
  goldeneBuerste = computed(() => (this.state().data?.luck?.goldene_buerste  ?? []) as any[]);
  hoelzerneBand  = computed(() => (this.state().data?.luck?.hoelzerne_bank   ?? []) as any[]);
  matchdayWins   = computed(() => (this.state().data?.luck?.matchday_wins    ?? []) as any[]);
  participationStats = computed(() => (this.state().data?.participation ?? []) as any[]);

  // Punkte-Herkunft: Note (eine Farbe) vs. Stats (Schattierungen einer zweiten Farbe, je Quelle).
  readonly pointSourceSegments = [
    { key: 'note',          label: 'Note',        color: 'var(--flat-sunflower)' },
    { key: 'goals',         label: 'Tore',        color: 'color-mix(in srgb, var(--flat-river) 100%, black 35%)' },
    { key: 'assists',       label: 'Assists',     color: 'var(--flat-river)' },
    { key: 'sds',           label: 'SdS',         color: 'color-mix(in srgb, var(--flat-river) 78%, white)' },
    { key: 'clean_sheet',   label: 'Weiße Weste', color: 'color-mix(in srgb, var(--flat-river) 60%, white)' },
    { key: 'participation', label: 'Einsatz',     color: 'color-mix(in srgb, var(--flat-river) 42%, white)' },
  ];

  // false = jeder Balken auf 100 % skaliert (Anteile), true = Balkenlänge relativ zum Team mit den
  // meisten Gesamtpunkten (macht Punkteunterschiede zwischen Teams sichtbar).
  pointSourceAbsolute = signal(false);

  pointSourceRows = computed(() => {
    const rows = ((this.state().data?.point_sources ?? []) as any[])
      .map(r => {
        const gross = this.pointSourceSegments.reduce((sum, s) => sum + +r[s.key], 0);
        if (gross <= 0) return null;
        const segments = this.pointSourceSegments.map(s => ({
          ...s,
          points: +r[s.key],
          share: +r[s.key] / gross * 100,
          pct: Math.round(+r[s.key] / gross * 100),
        }));
        return { ...r, deductions: +r.deductions, segments, gross };
      })
      .filter((r): r is NonNullable<typeof r> => r !== null);
    const maxGross = Math.max(...rows.map(r => r.gross), 1);
    return rows.map(r => ({ ...r, scale: r.gross / maxGross }));
  });

  // Mobile: Hölzerne Bank/Goldene Bürste/Glückspilze/Pechvögel als eigenes Swipe-Karussell (siehe
  // .position-points-carousel weiter unten, gleiches Muster) — Desktop bleibt unverändert im
  // .sidebar als gestapelte Cards. Nur Karten mit tatsächlich vorhandenen Daten werden aufgenommen
  // (gleiche @if-Bedingung wie die Desktop-Cards), Anzahl der Slides also variabel (0-4).
  luckCards = computed(() => {
    const cards: { key: string; icon: string; label: string; labelClass: string; rows: any[]; meta: (r: any) => string }[] = [];
    if (this.hoelzerneBand().length > 0) {
      cards.push({
        key: 'wood', icon: '/img/icons/bench.png', label: 'Hölzerne Bank', labelClass: 'luck-card__label--wood',
        rows: this.hoelzerneBand(), meta: (r) => `−${r.gap} Pkt`,
      });
    }
    if (this.goldeneBuerste().length > 0) {
      cards.push({
        key: 'gold', icon: '/img/icons/brush.png', label: 'Goldene Bürste', labelClass: 'luck-card__label--gold',
        rows: this.goldeneBuerste(), meta: (r) => `Sp. ${r.matchday_number} · ${r.points} Pkt`,
      });
    }
    if (this.lucky().length > 0) {
      cards.push({
        key: 'lucky', icon: '/img/icons/clover.png', label: 'Glückspilze', labelClass: 'luck-card__label--lucky',
        rows: this.lucky(), meta: (r) => `Sp. ${r.matchday_number} · ${r.points} Pkt`,
      });
    }
    if (this.unlucky().length > 0) {
      cards.push({
        key: 'unlucky', icon: '/img/icons/ghost.png', label: 'Pechvögel', labelClass: 'luck-card__label--unlucky',
        rows: this.unlucky(), meta: (r) => `Sp. ${r.matchday_number} · ${r.points} Pkt`,
      });
    }
    return cards;
  });

  activeLuckIndex = signal(0);
  @ViewChild('luckCarouselTrack') luckCarouselTrack?: ElementRef<HTMLElement>;

  onLuckCarouselScroll(): void {
    const el = this.luckCarouselTrack?.nativeElement;
    if (!el || el.clientWidth === 0) return;
    this.activeLuckIndex.set(Math.round(el.scrollLeft / el.clientWidth));
  }

  scrollToLuckIndex(index: number): void {
    const el = this.luckCarouselTrack?.nativeElement;
    if (!el) return;
    el.scrollTo({ left: index * el.clientWidth, behavior: 'smooth' });
    this.activeLuckIndex.set(index);
  }

  // Punkte nach Mannschaftsteil — 4 separat sortierte Mini-Tabellen (Torwart/Abwehr/Mittelfeld/
  // Sturm), je aus der pro Spieltag bereits denormalisierten team_rating.points_goalkeeper/
  // defender/midfielder/forward-Summe (siehe TeamRatingTrait::getSeasonStandings()) — bewusst auf
  // baseRows() statt rows() gebaut: der Live-Modus rechnet nur die Gesamt-/Tore-/Karten-Felder
  // live aus player_rating x team_lineup hoch, keine Mannschaftsteil-Aufschlüsselung, die Card
  // soll also unabhängig vom Live-Toggle immer den Stand der abgeschlossenen Spieltage zeigen.
  private readonly positionGroups: { key: 'total_points_goalkeeper' | 'total_points_defender' | 'total_points_midfielder' | 'total_points_forward'; label: string; color: string; icon: string }[] = [
    { key: 'total_points_goalkeeper', label: 'Torwart',    color: 'var(--position-goalkeeper)', icon: 'img/icons/position_goalkeeper.png' },
    { key: 'total_points_defender',   label: 'Abwehr',     color: 'var(--position-defender)',   icon: 'img/icons/position_defender.png' },
    { key: 'total_points_midfielder', label: 'Mittelfeld', color: 'var(--position-midfielder)', icon: 'img/icons/position_midfielder.png' },
    { key: 'total_points_forward',    label: 'Sturm',      color: 'var(--position-forward)',    icon: 'img/icons/position_forward.png' },
  ];

  // pct je Zeile = Anteil dieser Mannschaftsteil-Punkte an der Gesamtpunktzahl des Teams — pro
  // Gruppe neu berechnet statt am geteilten baseRows()-Objekt zu hängen, da jede der 4 Tabellen
  // einen anderen pct-Wert für dasselbe Team braucht.
  positionPoints = computed(() => {
    const base = this.baseRows();
    if (!base.length) return null;
    return this.positionGroups.map(g => ({
      key: g.key,
      label: g.label,
      color: g.color,
      icon: g.icon,
      rows: base
        .map(r => ({
          ...r,
          groupPoints: r[g.key] as number,
          groupPct: r.total_points > 0 ? (r[g.key] / r.total_points) * 100 : 0,
        }))
        .sort((a, b) => b.groupPoints - a.groupPoints),
    }));
  });

  // Hover auf eine ganze Zeile (nicht nur das Logo selbst, größere Trefferfläche) in einer der 4
  // Mannschaftsteil-Tabellen hebt dasselbe Team auch in den anderen 3 hervor (team_id-Abgleich,
  // unabhängig von der jeweiligen Sortierposition).
  hoveredPositionTeamId = signal<string | null>(null);
  onPositionTeamHover(teamId: string): void { this.hoveredPositionTeamId.set(teamId); }
  onPositionTeamLeave(): void { this.hoveredPositionTeamId.set(null); }

  // Mobile: die 4 Mannschaftsteil-Tabellen als Swipe-Karussell statt untereinander gestapelt —
  // ein Slide pro scroll-snap-Seite, activePositionIndex spiegelt die aktuell sichtbare Karte für
  // die Punkte-Indikatoren darunter (auch bei Finger-Swipe, nicht nur beim Klick auf einen Punkt).
  activePositionIndex = signal(0);
  @ViewChild('positionCarouselTrack') positionCarouselTrack?: ElementRef<HTMLElement>;

  onPositionCarouselScroll(): void {
    const el = this.positionCarouselTrack?.nativeElement;
    if (!el || el.clientWidth === 0) return;
    this.activePositionIndex.set(Math.round(el.scrollLeft / el.clientWidth));
  }

  scrollToPositionIndex(index: number): void {
    const el = this.positionCarouselTrack?.nativeElement;
    if (!el) return;
    el.scrollTo({ left: index * el.clientWidth, behavior: 'smooth' });
    this.activePositionIndex.set(index);
  }
  loading        = computed(() => this.state().loading);
  error          = computed(() => this.state().error);

  readonly chartW = 360;
  readonly chartH = 160;
  readonly padL   = 28;
  readonly padR   = 12;
  readonly padT   = 12;
  readonly padB   = 24;

  chartData = computed(() => {
    const series: any[] = this.state().data?.chart ?? [];
    if (!series.length) return null;

    const allPoints = series.flatMap((t: any) => t.series);
    if (allPoints.length < 2) return null;

    // minMatchday NICHT als 1 angenommen — bei aktivem Zeitraum-Filter beginnt die Serie erst
    // beim gewählten fromMatchday, sonst würde die Linie gestaucht/verzerrt gezeichnet.
    const minMatchday = Math.min(...allPoints.map((s: any) => s.matchday));
    const maxMatchday = Math.max(...allPoints.map((s: any) => s.matchday));
    const maxPoints   = Math.max(...allPoints.map((s: any) => s.points));
    if (maxMatchday === minMatchday || maxPoints === 0) return null;

    const plotW = this.chartW - this.padL - this.padR;
    const plotH = this.chartH - this.padT - this.padB;

    const toX = (md: number) => this.padL + ((md - minMatchday) / (maxMatchday - minMatchday)) * plotW;
    const toY = (pts: number) => this.padT + plotH - (pts / maxPoints) * plotH;

    const teams = series.map((t: any) => ({
      team_id:   t.team_id,
      team_name: t.team_name,
      season_id: t.season_id,
      color:     t.color ?? '#888888',
      // x-Koordinate je Spieltag, für den Hover-Tooltip: nächstgelegener Punkt zur Maus-Position
      // wird per Horizontal-Abstand gesucht (Crosshair-artig, unabhängig von der Y-Position).
      points: (t.series as any[]).map((s: any) => ({
        x: toX(s.matchday),
        matchday: s.matchday,
        points: s.points,
      })),
      pathD: (t.series as any[])
        .map((s: any, i: number) => `${i === 0 ? 'M' : 'L'}${toX(s.matchday).toFixed(1)},${toY(s.points).toFixed(1)}`)
        .join(' '),
    }));

    const yTicks = [
      { y: toY(maxPoints), label: String(maxPoints) },
      { y: toY(0),         label: '0' },
    ];

    const xLabels = [
      { x: toX(minMatchday), label: `Sp. ${minMatchday}` },
      { x: toX(maxMatchday), label: `ST ${maxMatchday}` },
    ];

    return { teams, yTicks, xLabels };
  });

  // Für alle direkten [routerLink]-Team-Links auf dieser Seite (Karussells/Sidebar-Karten) —
  // die Smart-Navigation auf /team/:id soll immer "alle Teams der Saison" zur Verfügung haben,
  // unabhängig davon, über welchen Link/welche Karte man dorthin gekommen ist.
  setTeamNavContext(): void {
    this.teamNav.setContext(this.sortedRows().map((r) => r.team_id));
  }

  navigateToTeam(teamId: string): void {
    this.setTeamNavContext();
    this.router.navigate(['/team', teamId]);
  }

  logoErrors = new Set<string>();
  onLogoError(teamId: string) { this.logoErrors.add(teamId); }

  // Eigenes, vom persistenten logoErrors entkoppeltes Set fürs Tooltip-Logo — die Tooltip-<img>
  // wechselt beim Drüberfahren schnell zwischen vielen Teams durch, ein einzelner Lade-Fehler
  // dort (z.B. wegen des schnellen Src-Wechsels abgebrochene Requests) soll nicht dazu führen,
  // dass die Tabelle/Einsatzquote-Liste für dasselbe Team fälschlich auf den Platzhalter springt.
  chartLogoErrors = new Set<string>();
  onChartLogoError(teamId: string) { this.chartLogoErrors.add(teamId); }

  // ── Custom Hover-Tooltip über einer Saisonverlauf-Linie ──────────────────────────
  chartTooltip = signal<{ team_id: string; team_name: string; season_id: string; matchday: number; points: number } | null>(null);
  chartTooltipPos = signal<{ top: number; left: number } | null>(null);

  onChartHover(
    event: MouseEvent,
    team: { team_id: string; team_name: string; season_id: string; points: { x: number; matchday: number; points: number }[] },
  ): void {
    const svg = (event.currentTarget as SVGGraphicsElement).ownerSVGElement;
    if (!svg || !team.points.length) return;

    const pt = svg.createSVGPoint();
    pt.x = event.clientX;
    pt.y = event.clientY;
    const ctm = svg.getScreenCTM();
    if (!ctm) return;
    const svgPt = pt.matrixTransform(ctm.inverse());

    // Nächstgelegener Spieltag zur Maus-X-Position — reines Crosshair, keine Y-Distanz nötig.
    let nearest = team.points[0];
    let minDist = Math.abs(nearest.x - svgPt.x);
    for (const p of team.points) {
      const d = Math.abs(p.x - svgPt.x);
      if (d < minDist) { minDist = d; nearest = p; }
    }

    this.chartTooltip.set({
      team_id:   team.team_id,
      team_name: team.team_name,
      season_id: team.season_id,
      matchday:  nearest.matchday,
      points:    nearest.points,
    });

    // Einfaches horizontales Clamping statt Re-Messung per rAF (wie onParticipationHover) — der
    // Tooltip folgt der Maus bei jeder mousemove, ein rAF-Messzyklus pro Event wäre unnötig teuer.
    const margin = 12;
    const assumedHalfWidth = 90;
    const left = Math.min(Math.max(event.clientX, margin + assumedHalfWidth), window.innerWidth - margin - assumedHalfWidth);
    this.chartTooltipPos.set({ top: event.clientY, left });
  }

  onChartLeave(): void {
    this.chartTooltip.set(null);
    this.chartTooltipPos.set(null);
  }

  // ── Custom Hover-Tooltip über einem Einsatzquote-Balken — gleiches Edge-Clamp-Muster wie
  // betting-office.component.ts's onWinHover()/onWinLeave() bzw. h2h-match.component.ts's
  // onEntryHover()/onEntryLeave().
  @ViewChild('participationTooltipEl') participationTooltipEl?: ElementRef<HTMLElement>;
  tooltipRow    = signal<any | null>(null);
  tooltipPos    = signal<{ top: number; left: number } | null>(null);
  tooltipBelow  = signal(false);
  tooltipReady  = signal(false);

  private static readonly TOOLTIP_EDGE_MARGIN = 24;
  private hoverSeq = 0;

  onParticipationHover(event: MouseEvent, r: any): void {
    const seq = ++this.hoverSeq;
    // Handler hängt am ganzen (display:contents-)Zeilen-Link, damit der Tooltip nicht springt,
    // wenn man z.B. vom Namen Richtung Balken bewegt — currentTarget selbst hat aber keine eigene
    // Box (display:contents), daher immer am Balken innerhalb der Zeile verankern.
    const rowEl = event.currentTarget as HTMLElement;
    const anchorEl = rowEl.querySelector('.participation-bar') ?? rowEl;
    const rect = anchorEl.getBoundingClientRect();
    this.tooltipRow.set(r);
    this.tooltipBelow.set(false);
    this.tooltipReady.set(false);
    this.tooltipPos.set({ top: rect.top, left: rect.left + rect.width / 2 });

    requestAnimationFrame(() => {
      const el = this.participationTooltipEl?.nativeElement;
      if (!el || seq !== this.hoverSeq) return;

      const margin = TableComponent.TOOLTIP_EDGE_MARGIN;
      let top   = rect.top;
      let left  = rect.left + rect.width / 2;
      let below = false;

      const tipRect = el.getBoundingClientRect();

      if (tipRect.top < margin) {
        below = true;
        top = rect.bottom;
      }

      const halfWidth = tipRect.width / 2;
      const maxLeft   = window.innerWidth - margin - halfWidth;
      const minLeft   = margin + halfWidth;
      if (left > maxLeft) left = maxLeft;
      if (left < minLeft) left = minLeft;

      this.tooltipBelow.set(below);
      this.tooltipPos.set({ top, left });
      this.tooltipReady.set(true);
    });
  }

  onParticipationLeave(): void {
    this.hoverSeq++;
    this.tooltipRow.set(null);
    this.tooltipPos.set(null);
    this.tooltipReady.set(false);
  }

  constructor() {
    this.cache.ensureSeasons();
    this.cache.ensureLeague();
  }
}
