import { Component, OnDestroy, computed, effect, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-h2h-match',
  standalone: false,
  templateUrl: './h2h-match.component.html',
  styleUrl: './h2h-match.component.scss',
})
export class H2HMatchComponent implements OnDestroy {
  private api  = inject(ApiService);
  private auth = inject(AuthService);
  cache        = inject(DataCacheService);

  private id$ = inject(ActivatedRoute).paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`h2h/${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' })),
        )
      )
    )
  );

  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error ?? null);
  data    = computed(() => this.state()?.data ?? null);

  match      = computed(() => this.data()?.match ?? null);
  matchday   = computed(() => this.data()?.matchday ?? null);
  homeTeam   = computed(() => this.data()?.home_team ?? null);
  awayTeam   = computed(() => this.data()?.away_team ?? null);
  homeRating = computed(() => this.data()?.home_rating ?? null);
  awayRating = computed(() => this.data()?.away_rating ?? null);
  homeLineup = computed(() => (this.data()?.home_lineup ?? []) as any[]);
  awayLineup = computed(() => (this.data()?.away_lineup ?? []) as any[]);
  homeBench  = computed(() => (this.data()?.home_bench  ?? []) as any[]);
  awayBench  = computed(() => (this.data()?.away_bench  ?? []) as any[]);

  // Whether to render the pitch section at all — independent of lineupsReady() (which now
  // requires a COMPLETE valid XI on both sides, see below, purely to gate betting/odds). This
  // stays lenient (any team_lineup data on either side) so a one-sided or partial lineup still
  // renders the field with showHomeInvalidHint()/showAwayInvalidHint() taking over per side,
  // instead of collapsing the whole section to the "not available yet" placeholder.
  hasAnyLineupData = computed(() =>
    this.homeLineup().length > 0 || this.homeBench().length > 0 ||
    this.awayLineup().length > 0 || this.awayBench().length > 0
  );

  // A saved team_lineup is always either a complete valid XI (11 nominated) or a still-reachable
  // partial build/gap (see team_lineup.database.php's isReachableFormation, and POST /sell which
  // only clears the sold player's own entry instead of resetting the whole lineup to bench) — this
  // page is read-only regardless of ownership (lineup editing only happens on
  // /team/:id/aufstellung), so unlike lineup.component.ts there's no owner exception here: an
  // incomplete lineup shows the hint for everyone, including its own manager.
  isHomeLineupComplete = computed(() => this.homeLineup().length === 11);
  isAwayLineupComplete = computed(() => this.awayLineup().length === 11);

  showHomeInvalidHint = computed(() => !this.isHomeLineupComplete());
  showAwayInvalidHint = computed(() => !this.isAwayLineupComplete());

  // Mobile pitch view shows one team at a time (see selectedSide below).
  showSelectedInvalidHint = computed(() =>
    this.selectedSide() === 'home' ? this.showHomeInvalidHint() : this.showAwayInvalidHint()
  );

  // Deterministische Pseudo-Quote (Heim/Unentschieden/Auswärts) aus Marktwert+Saisonpunkten der
  // aufgestellten Spieler — reine Orientierung, keine echten Einsätze; siehe
  // H2HTrait::calculateH2HOdds() im Backend für die Berechnung.
  odds = computed(() => this.data()?.odds ?? null);

  // Zwischenwerte der Quoten-Berechnung — nur vom Backend befüllt, wenn der Aufrufer Admin ist
  // (siehe H2HController::get()). Grundlage für die Transparenz-Card weiter unten.
  oddsBreakdown = computed(() => this.data()?.odds_breakdown ?? null);

  // Einklappzustand der Quoten-Berechnung-Card — rein clientseitig, kein Persistieren nötig.
  oddsBreakdownExpanded = signal(true);

  // Alle bisherigen (abgeschlossenen) H2H-Begegnungen zwischen genau diesen beiden Managern,
  // liga- und saisonübergreifend — siehe H2HTrait::getH2HHeadToHeadHistory() im Backend.
  headToHead = computed(() => (this.data()?.head_to_head ?? []) as any[]);

  // Nach Saison gruppiert für die Kartenanzeige — headToHead() ist bereits absteigend nach
  // kickoff_date sortiert, Matches derselben Saison stehen also immer schon hintereinander.
  headToHeadGroups = computed(() => {
    const groups: { season_id: string; matches: any[] }[] = [];
    for (const m of this.headToHead()) {
      const last = groups[groups.length - 1];
      if (last && last.season_id === m.season_id) {
        last.matches.push(m);
      } else {
        groups.push({ season_id: m.season_id, matches: [m] });
      }
    }
    return groups;
  });

  // ── Tipp abgeben (bis Anpfiff privat/änderbar, danach für alle sichtbar) ───────

  // predictionsOverride greift, sobald der clientseitige Anpfiff-Check (kickoffPassed) zuschlägt,
  // bevor die ursprünglich geladene Antwort das noch weiß (siehe refetchPredictionsAfterKickoff) —
  // so verschwindet die Tipp-Karte exakt am Anpfiff, auch wenn die Seite seit vorher offen ist,
  // statt erst beim nächsten manuellen Neuladen.
  private predictionsOverride = signal<any>(null);
  predictions       = computed(() => this.predictionsOverride() ?? this.data()?.predictions ?? null);
  predictionEntries = computed(() => (this.predictions()?.entries ?? []) as any[]);

  // Tickt alle 15s, damit kickoffPassed() auch ohne Nutzerinteraktion irgendwann auf true kippt,
  // wenn die Seite über den tatsächlichen Anpfiff hinaus offen bleibt.
  private now = signal(Date.now());
  private tickHandle?: ReturnType<typeof setInterval>;

  kickoffPassed = computed(() => {
    const kd = this.predictions()?.kickoff_date;
    return kd ? this.now() >= new Date(kd).getTime() : false;
  });

  // "Gesperrt" entweder weil der Server es schon so meldet, oder weil die lokale Uhr den Anpfiff
  // erkennt, bevor ein Refetch die serverseitige Bestätigung nachgeliefert hat.
  isRevealed = computed(() => (this.predictions()?.locked ?? false) || this.kickoffPassed());

  // Gibt es nach Anpfiff keinen einzigen Tipp, wird die ganze Auswertungs-Card ausgeblendet
  // statt nur "Niemand hat getippt." darin anzuzeigen. Der kurze Übergangszustand ("Wird
  // ausgewertet…", locked noch nicht bestätigt) bleibt davon unberührt.
  hideEmptyResult = computed(() =>
    this.isRevealed() && (this.predictions()?.locked ?? false) && this.predictionEntries().length === 0
  );

  // Tippen ist nur für Matches des aktuellen Spieltags möglich (siehe Backend
  // H2HPredictionTrait::isCurrentH2HMatchday) — nur vor Anpfiff im Response enthalten, siehe
  // getH2HPredictionState(). Bei einem bereits geplanten zukünftigen Spieltag bleibt die
  // gesamte Tipp-Karte unsichtbar statt nur die Buttons zu deaktivieren.
  canTipThisMatchday = computed(() => this.predictions()?.is_current_matchday ?? false);

  // Manager eines der beiden beteiligten Teams dürfen nicht auf ihr eigenes Match tippen — sie
  // könnten die angezeigte Quote sonst durch Verändern der eigenen Aufstellung manipulieren.
  isOwnMatch = computed(() => this.predictions()?.is_own_match ?? false);

  // Tippen erst, sobald beide Teams eine Aufstellung für diesen Spieltag gesetzt haben — vorher
  // ist die Quote nur der neutrale "keine Daten"-Fallback (siehe Backend calculateH2HOdds()),
  // auf den ein Tipp keinen echten Aussagewert hätte. Default true, falls das Backend das Feld
  // (nur vor Anpfiff im Response) noch nicht mitschickt.
  lineupsReady = computed(() => this.predictions()?.lineups_ready ?? true);

  // Reine Anzahl bereits abgegebener Tipps (nicht wer/was) — die Tipps selbst bleiben vor
  // Anpfiff geheim (siehe hideCard()-Kommentar), die Anzahl darf trotzdem schon angezeigt werden.
  submittedCount = computed(() => this.predictions()?.submitted_count ?? 0);
  submittedCountLabel = computed(() => {
    const n = this.submittedCount();
    if (n === 0) return 'Noch niemand hat getippt.';
    if (n === 1) return '1 Manager hat bereits getippt.';
    return `${n} Manager haben bereits getippt.`;
  });

  // isOwnMatch() blendet die Card bewusst NICHT mehr aus (siehe Template) — dort erscheint
  // stattdessen ein Hinweis, warum getippt werden könnte, aber nicht darf.
  hideCard = computed(() =>
    this.hideEmptyResult() ||
    (!this.isRevealed() && (!this.canTipThisMatchday() || (!this.isOwnMatch() && !this.lineupsReady())))
  );

  private refetchedAfterKickoff = false;

  private refetchPredictionsAfterKickoff(): void {
    if (this.refetchedAfterKickoff) return;
    const matchId = this.match()?.id;
    if (!matchId) return;
    this.refetchedAfterKickoff = true;
    this.api.get<any>(`h2h/${matchId}`).subscribe({
      next: full => this.predictionsOverride.set(full?.predictions ?? null),
      error: () => { this.refetchedAfterKickoff = false; },
    });
  }

  // Optimistisches Update, damit der Klick sofort im UI ankommt statt auf den nächsten
  // Server-Roundtrip zu warten — bei Fehlschlag (z.B. Anpfiff inzwischen erfolgt) wird die
  // Auswahl zurückgesetzt und predictionError zeigt eine kurze Fehlermeldung an.
  // undefined = keine lokale Override (myPick() greift auf predictions().my_pick zurück); null
  // ist davon bewusst unterschieden — steht für "Tipp wurde gerade lokal entfernt", sonst würde
  // myPick() nach dem Entfernen sofort wieder auf den noch nicht neu geladenen Server-Wert
  // zurückfallen und der entfernte Pick bliebe optisch ausgewählt.
  private optimisticPick = signal<'home' | 'draw' | 'away' | null | undefined>(undefined);
  myPick          = computed(() => {
    const o = this.optimisticPick();
    return o !== undefined ? o : (this.predictions()?.my_pick ?? null);
  });
  submittingPick  = signal(false);
  predictionError = signal<string | null>(null);

  pickLabel(pick: string): string {
    if (pick === 'home') return this.homeTeam()?.team_name ?? 'Heimsieg';
    if (pick === 'away') return this.awayTeam()?.team_name ?? 'Auswärtssieg';
    return 'Unentschieden';
  }

  // Klick auf den bereits ausgewählten Pick entfernt den Tipp wieder, statt ihn erneut zu
  // speichern — nur möglich, solange die Tippphase offen ist (aktueller Spieltag, vor Anpfiff,
  // kein eigenes Match, siehe dieselben Guards wie unten).
  submitPrediction(pick: 'home' | 'draw' | 'away'): void {
    if (this.myPick() === pick) {
      this.removePrediction();
      return;
    }

    const matchId = this.match()?.id;
    if (!matchId || this.submittingPick() || this.isRevealed() || !this.canTipThisMatchday() || this.isOwnMatch()) return;

    const previous = this.optimisticPick();
    this.optimisticPick.set(pick);
    this.submittingPick.set(true);
    this.predictionError.set(null);

    // Nur die Quote des gewählten Picks wird 1:1 als Snapshot mitgeschickt (siehe
    // H2HPredictionTrait::submitH2HPrediction) — sie kann sich bis Anpfiff durch
    // Aufstellungsänderungen noch von der Quote unterscheiden, die am Ende gilt.
    const body = { match_id: matchId, pick, odds: this.odds()?.[pick] ?? null };

    this.api.post<{ status: boolean; message?: string }>('h2h_prediction', body).subscribe({
      next: () => this.submittingPick.set(false),
      error: (err) => {
        this.optimisticPick.set(previous);
        this.submittingPick.set(false);
        this.predictionError.set(err?.error?.message ?? 'Tipp konnte nicht gespeichert werden.');
      },
    });
  }

  removePrediction(): void {
    const matchId = this.match()?.id;
    if (!matchId || this.submittingPick() || this.isRevealed() || !this.canTipThisMatchday() || this.isOwnMatch()) return;

    const previous = this.optimisticPick();
    this.optimisticPick.set(null);
    this.submittingPick.set(true);
    this.predictionError.set(null);

    this.api.delete<{ status: boolean; message?: string }>(`h2h_prediction/${matchId}`).subscribe({
      next: () => this.submittingPick.set(false),
      error: (err) => {
        this.optimisticPick.set(previous);
        this.submittingPick.set(false);
        this.predictionError.set(err?.error?.message ?? 'Tipp konnte nicht entfernt werden.');
      },
    });
  }

  // ── Tipp-Verteilung als Kreisdiagramm (rein CSS conic-gradient, gleiches Muster wie
  // session-heatmap.component.ts's devicePie) — für die echte Auswertung (predictionEntries)
  // und die separate Admin-Vorschau (previewEntries) gleichermaßen nutzbar.
  readonly drawColor = '#a4b0be'; // matcht den grauen Unentschieden-Button (--pick-color default)

  private buildPickPie(entries: any[] | null | undefined): {
    gradient: string;
    homeCount: number; drawCount: number; awayCount: number;
    homePct: number; drawPct: number; awayPct: number;
  } | null {
    if (!entries || entries.length === 0) return null;

    let homeCount = 0, drawCount = 0, awayCount = 0;
    for (const e of entries) {
      if (e.pick === 'home') homeCount++;
      else if (e.pick === 'away') awayCount++;
      else drawCount++;
    }

    // Reihenfolge im Uhrzeigersinn ab 12 Uhr (conic-gradient-Default): Auswärts, Unentschieden,
    // Heim — dadurch liegt Heim optisch links, Auswärts rechts und Unentschieden unten, wenn
    // beide Teams getippt wurden. Rundungsrest landet auf Heim (letztes Segment, endet bei
    // 100%), damit der Gradient exakt schließt statt eine Lücke/Überlappung am oberen Rand zu
    // hinterlassen.
    const total    = entries.length;
    const awayPct  = Math.round(awayCount / total * 100);
    const drawPct  = Math.round(drawCount / total * 100);
    const homePct  = 100 - awayPct - drawPct;
    const awayEnd  = awayPct;
    const drawEnd  = awayPct + drawPct;

    const homeColor = this.homeTeam()?.color ?? this.drawColor;
    const awayColor = this.awayTeam()?.color ?? this.drawColor;

    return {
      gradient: `conic-gradient(${awayColor} 0% ${awayEnd}%, ${this.drawColor} ${awayEnd}% ${drawEnd}%, ${homeColor} ${drawEnd}% 100%)`,
      homeCount, drawCount, awayCount, homePct, drawPct, awayPct,
    };
  }

  resultPie = computed(() => this.buildPickPie(this.predictionEntries()));

  // ── Admin-Vorschau: Auswertung testweise schon vor Anpfiff sichtbar, als eigene, separat
  // geladene Karte (eigener Zustand statt predictions(), damit locked/my_pick unangetastet
  // bleiben — normale Manager sehen davon nichts, siehe H2HPredictionTrait::getH2HPredictionState).
  // Wird automatisch geladen (siehe constructor-Effect), kein Klick nötig.

  isAdmin = computed(() => this.auth.isAdmin());

  private previewData    = signal<any>(null);
  previewEntries          = computed(() => (this.previewData()?.preview_entries ?? []) as any[]);
  previewPie              = computed(() => this.buildPickPie(this.previewEntries()));

  private previewFetchTriggered = false;

  private loadPreview(): void {
    if (this.previewFetchTriggered) return;
    const matchId = this.match()?.id;
    if (!matchId) return;
    this.previewFetchTriggered = true;
    this.api.get<any>(`h2h/${matchId}?preview=1`).subscribe({
      next: full => this.previewData.set(full?.predictions ?? null),
      error: () => { this.previewFetchTriggered = false; },
    });
  }

  homeSdsDefenders = computed(() => {
    const count = this.homeRating()?.sds_defender ?? 0;
    const named = this.homeLineup().filter(p =>
      (p.position === 'DEFENDER' || p.position === 'GOALKEEPER') && p.sds
    );
    return named.length >= count
      ? named.slice(0, count)
      : [...named, ...Array(count - named.length).fill(null)];
  });
  awaySdsDefenders = computed(() => {
    const count = this.awayRating()?.sds_defender ?? 0;
    const named = this.awayLineup().filter(p =>
      (p.position === 'DEFENDER' || p.position === 'GOALKEEPER') && p.sds
    );
    return named.length >= count
      ? named.slice(0, count)
      : [...named, ...Array(count - named.length).fill(null)];
  });

  private assistBlocks(lineup: any[]): string[][] {
    const flat = lineup.filter(p => p.assists > 0).flatMap(p => Array(p.assists).fill(p.displayname));
    const blocks: string[][] = [];
    for (let i = 0; i + 2 < flat.length; i += 3) blocks.push(flat.slice(i, i + 3));
    return blocks;
  }

  homeGoalEvents = computed(() => [
    ...this.homeLineup().filter(p => p.goals > 0).flatMap(p =>
      Array(p.goals).fill({ type: 'goal' as const, label: p.displayname })
    ),
    ...this.assistBlocks(this.homeLineup()).map(b => ({ type: 'assist' as const, label: b.join(', ') })),
  ]);
  awayGoalEvents = computed(() => [
    ...this.awayLineup().filter(p => p.goals > 0).flatMap(p =>
      Array(p.goals).fill({ type: 'goal' as const, label: p.displayname })
    ),
    ...this.assistBlocks(this.awayLineup()).map(b => ({ type: 'assist' as const, label: b.join(', ') })),
  ]);

  awayGoalsBlocked = computed(() =>
    Math.min(this.homeSdsDefenders().length, this.awayGoalEvents().length)
  );
  homeGoalsBlocked = computed(() =>
    Math.min(this.awaySdsDefenders().length, this.homeGoalEvents().length)
  );

  managerPhotoUrl(managerId: string): string {
    return `https://img.die-bestesten.de/manager/${managerId}.jpg`;
  }

  // Gleiche rotierte Farbfläche zur Positions-Visualisierung wie team/lineup/lineup.component
  // (.bench-player__pos) — hier für die Bank-Karten neben dem H2H-Feld übernommen.
  positionColor(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'var(--position-goalkeeper)',
      DEFENDER:   'var(--position-defender)',
      MIDFIELDER: 'var(--position-midfielder)',
      FORWARD:    'var(--position-forward)',
    };
    return map[pos] ?? 'transparent';
  }

  private readonly phaseLabels: Record<string, string> = {
    group: 'Gruppenphase', quarterfinal: 'Viertelfinale', semifinal: 'Halbfinale', final: 'Finale',
  };
  phaseLabel = computed(() => this.phaseLabels[this.match()?.phase ?? ''] ?? '');

  teamLogoUrl(seasonId: string, teamId: string): string {
    return `https://img.die-bestesten.de/team/${seasonId}/${teamId}.png`;
  }

  showBench = false;

  homeLogoUrl = computed(() => {
    const t = this.homeTeam();
    return t?.id && t?.season_id ? `https://img.die-bestesten.de/team/${t.season_id}/${t.id}.png` : null;
  });

  awayLogoUrl = computed(() => {
    const t = this.awayTeam();
    return t?.id && t?.season_id ? `https://img.die-bestesten.de/team/${t.season_id}/${t.id}.png` : null;
  });

  homePhotoUrl(p: any): string {
    return `https://img.die-bestesten.de/player/${p.photo_season_id}/${p.player_id}.png`;
  }

  gradeInt(grade: any): number {
    return Math.round(+grade * 10);
  }

  // ── Mobile: pitch view of the selected team ──────────────────────────────────

  readonly pitchPositions = ['FORWARD', 'MIDFIELDER', 'DEFENDER', 'GOALKEEPER'];

  selectedSide = signal<'home' | 'away'>('home');

  selectedTeam   = computed(() => (this.selectedSide() === 'home' ? this.homeTeam()   : this.awayTeam()));
  selectedLineup = computed(() => (this.selectedSide() === 'home' ? this.homeLineup() : this.awayLineup()));
  selectedBench  = computed(() => (this.selectedSide() === 'home' ? this.homeBench()  : this.awayBench()));

  playersByPosition(pos: string): any[] {
    return this.positionPlayers(this.selectedLineup(), pos);
  }

  // ── Desktop: combined field for both teams ────────────────────────────────────
  // Home läuft links GK→FWD, Away gespiegelt FWD→GK, sodass beide Stürmerreihen in der
  // Mitte an der Mittellinie aufeinandertreffen (siehe h2h-match.component.scss .h2h-field).

  readonly homeFieldPositions = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];
  readonly awayFieldPositions = ['FORWARD', 'MIDFIELDER', 'DEFENDER', 'GOALKEEPER'];

  positionPlayers(lineup: any[], pos: string): any[] {
    return lineup.filter(p => p.position === pos);
  }

  constructor() {
    // Für cache.seasonName() in der "Bisherige Begegnungen"-Card — Begegnungen können aus
    // anderen Saisons stammen als der aktuell aktiven, deren Name sonst nicht im Cache läge.
    this.cache.ensureSeasons();
    this.tickHandle = setInterval(() => this.now.set(Date.now()), 15_000);
    effect(() => {
      if (this.isRevealed() && !(this.predictions()?.locked ?? false)) {
        this.refetchPredictionsAfterKickoff();
      }
    });
    effect(() => {
      if (this.isAdmin() && !this.isRevealed() && this.match()?.id) {
        this.loadPreview();
      }
    });
  }

  ngOnDestroy(): void {
    if (this.tickHandle !== undefined) clearInterval(this.tickHandle);
  }
}
