import { Component, inject, signal } from '@angular/core';
import { forkJoin, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { MatchdaySummary, MatchdaySummaryService } from '../../core/matchday-summary.service';

interface MatchdayOption {
  id: string;
  number: number;
  completed: boolean | number | string;
}

interface ManagerOption {
  manager_id: string;
  manager_name: string;
  team_name: string;
}

/**
 * Füllt jeden Bereich, den der Manager an dem Spieltag nicht hatte, mit einem Beispiel — so lässt sich die ganze
 * Einblendung ansehen, auch wenn z.B. niemand getippt oder ein Achievement bekommen hat.
 */
function withExamples(s: MatchdaySummary): MatchdaySummary {
  const r = s.result;
  return {
    ...s,
    result: r && {
      ...r,
      highlights: r.highlights.length || !r.valid ? r.highlights : [
        { player_id: 'example-1', displayname: 'Beispielstürmer', position: 'FORWARD', photo_season_id: null, goals: 2, assists: 0, sds: true, points: 22 },
        { player_id: 'example-2', displayname: 'Beispielspielmacher', position: 'MIDFIELDER', photo_season_id: null, goals: 0, assists: 1, sds: false, points: 9 },
      ],
      income: r.income > 0 ? r.income : 1_640_000,
      fine: r.fine > 0 ? r.fine : 2,
      h2h: r.h2h ?? {
        match_id: 'example', phase: 'group', home: true,
        opponent: { team_id: 'example', team_name: 'Beispielgegner', color: null, season_id: s.matchday.season_id },
        goals_for: 2, goals_against: 1, outcome: 'win',
      },
    },
    extras: {
      lukaten_entries: s.extras.lukaten_entries ?? { amount: 42, by_type: { participation: 22, note: 14, stats: 6 } },
      bets: s.extras.bets ?? { tips: 3, correct: 2, stakes: 30, payouts: 71.5 },
      packs: s.extras.packs.length ? s.extras.packs : [
        { source: 'matchday_best', milestone_points: null, size: 5 },
        { source: 'milestone', milestone_points: 800, size: 3 },
      ],
      achievements: s.extras.achievements.length ? s.extras.achievements : [
        { name: 'Beispiel-Achievement', icon: 'cup', level: 'silver', reason: 'So steht hier der Anlass des Achievements' },
      ],
    },
  };
}

/**
 * /verwaltung/zusammenfassung: Vorschau der Spieltags-Zusammenfassung, bevor sie jemand zu sehen bekommt — Manager
 * und abgeschlossenen Spieltag der aktuellen Liga wählen, die Einblendung öffnet sich mit den echten Daten
 * (GET /matchday_summary/preview). Es wird nichts gespeichert und niemandem etwas angezeigt.
 */
@Component({
  selector: 'app-matchday-summary-preview',
  standalone: false,
  templateUrl: './matchday-summary-preview.component.html',
  styleUrl: './matchday-summary-preview.component.scss',
})
export class MatchdaySummaryPreviewComponent {
  private api = inject(ApiService);
  private summary = inject(MatchdaySummaryService);

  /** abgeschlossene Spieltage der laufenden Saison, neueste zuerst — undefined = lädt, null = Fehler */
  matchdays = signal<MatchdayOption[] | null | undefined>(undefined);
  managers = signal<ManagerOption[]>([]);
  matchdayId = signal<string | null>(null);
  managerId = signal<string | null>(null);
  /** jeden Bereich zeigen, auch die, die der Manager an dem Spieltag nicht hatte */
  examples = signal(false);
  loading = signal(false);
  error = signal<string | null>(null);
  /** Tabelle matchday_summary vorhanden? null = unbekannt */
  tableReady = signal<boolean | null>(null);

  constructor() {
    this.api.get<{ id: string }>('season/active').pipe(
      switchMap(season => forkJoin({
        matchdays: this.api.get<MatchdayOption[]>(`matchday?season_id=${season.id}`),
        teams: this.api.get<ManagerOption[]>(`team?season_id=${season.id}`),
      })),
    ).subscribe({
      next: ({ matchdays, teams }) => {
        const completed = matchdays.filter(m => Number(m.completed) === 1 || m.completed === true)
          .sort((a, b) => Number(b.number) - Number(a.number));
        const managers = [...teams].sort((a, b) => a.manager_name.localeCompare(b.manager_name, 'de'));
        this.matchdays.set(completed);
        this.managers.set(managers);
        this.matchdayId.set(completed[0]?.id ?? null);
        this.managerId.set(managers[0]?.manager_id ?? null);
      },
      error: () => this.matchdays.set(null),
    });

    this.api.get<{ ready: boolean }>('matchday_summary').subscribe({
      next: r => this.tableReady.set(r.ready),
      error: () => {},
    });
  }

  open(): void {
    const managerId = this.managerId();
    const matchdayId = this.matchdayId();
    if (!managerId || !matchdayId || this.loading()) return;
    this.loading.set(true);
    this.error.set(null);
    this.api.get<MatchdaySummary>(`matchday_summary/preview?manager_id=${managerId}&matchday_id=${matchdayId}`).subscribe({
      next: s => {
        this.loading.set(false);
        this.summary.show([this.examples() ? withExamples(s) : s], true);
      },
      error: err => {
        this.loading.set(false);
        this.error.set(err?.error?.message ?? 'Die Vorschau konnte nicht geladen werden.');
      },
    });
  }
}
