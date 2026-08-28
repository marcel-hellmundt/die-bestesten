import { Component, OnDestroy, computed, effect, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';

@Component({
  selector: 'app-h2h-match',
  standalone: false,
  templateUrl: './h2h-match.component.html',
  styleUrl: './h2h-match.component.scss',
})
export class H2HMatchComponent implements OnDestroy {
  private api  = inject(ApiService);
  private auth = inject(AuthService);

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

  // Deterministische Pseudo-Quote (Heim/Unentschieden/Auswärts) aus Marktwert+Saisonpunkten der
  // aufgestellten Spieler — reine Orientierung, keine echten Einsätze; siehe
  // H2HTrait::calculateH2HOdds() im Backend für die Berechnung.
  odds = computed(() => this.data()?.odds ?? null);

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
  private optimisticPick = signal<'home' | 'draw' | 'away' | null>(null);
  myPick          = computed(() => this.optimisticPick() ?? this.predictions()?.my_pick ?? null);
  submittingPick  = signal(false);
  predictionError = signal<string | null>(null);

  pickLabel(pick: string): string {
    if (pick === 'home') return this.homeTeam()?.team_name ?? 'Heimsieg';
    if (pick === 'away') return this.awayTeam()?.team_name ?? 'Auswärtssieg';
    return 'Unentschieden';
  }

  submitPrediction(pick: 'home' | 'draw' | 'away'): void {
    const matchId = this.match()?.id;
    if (!matchId || this.submittingPick() || this.isRevealed()) return;

    const previous = this.optimisticPick();
    this.optimisticPick.set(pick);
    this.submittingPick.set(true);
    this.predictionError.set(null);

    this.api.post<{ status: boolean; message?: string }>('h2h_prediction', { match_id: matchId, pick }).subscribe({
      next: () => this.submittingPick.set(false),
      error: (err) => {
        this.optimisticPick.set(previous);
        this.submittingPick.set(false);
        this.predictionError.set(err?.error?.message ?? 'Tipp konnte nicht gespeichert werden.');
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

  phaseLabel = computed(() => {
    const map: Record<string, string> = {
      group: 'Gruppenphase', quarterfinal: 'Viertelfinale', semifinal: 'Halbfinale', final: 'Finale',
    };
    return map[this.match()?.phase ?? ''] ?? '';
  });

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
