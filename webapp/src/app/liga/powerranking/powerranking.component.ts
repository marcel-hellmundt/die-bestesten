import { Component, computed, effect, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, switchMap } from 'rxjs';
import { CdkDragDrop, moveItemInArray } from '@angular/cdk/drag-drop';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { environment } from '../../../environments/environment';
import { PowerrankingPick } from '../../core/models/powerranking-pick.model';

interface LigaTeamLite {
  id: string;
  team_name: string;
  color: string | null;
  season_id: string;
  manager_name: string;
}

interface PowerrankingStanding {
  team_id: string;
  team_name: string;
  color: string | null;
  manager_name: string;
  season_id: string;
  total_points: number;
  actual_position: number;
}

interface PowerrankingEntry {
  manager_id: string;
  manager_name: string;
  alias: string | null;
  total_deviation: number;
  picks: { team_id: string; predicted_position: number; actual_position: number | null; deviation: number | null }[];
}

interface PowerrankingResponse {
  locked: boolean;
  preview?: boolean;
  season_id: string;
  kickoff_date: string | null;
  my_picks?: { team_id: string; position: number }[];
  submitted_count?: number;
  total_managers?: number;
  standings?: PowerrankingStanding[];
  entries?: PowerrankingEntry[];
}

@Component({
  selector: 'app-powerranking',
  standalone: false,
  templateUrl: './powerranking.component.html',
  styleUrl: './powerranking.component.scss',
})
export class PowerrankingComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);

  myManagerId = this.auth.getManagerId();
  isAdmin = this.auth.isAdmin();

  private reloadTrigger = signal(0);
  reload(): void { this.reloadTrigger.update(v => v + 1); }

  private state = toSignal(
    toObservable(this.reloadTrigger).pipe(
      switchMap(() =>
        this.api.get<PowerrankingResponse>('powerranking').pipe(
          map(data => ({ data: data as PowerrankingResponse | null, loading: false, error: null as string | null })),
          catchError(() => of({ data: null as PowerrankingResponse | null, loading: false, error: 'Fehler beim Laden' as string | null })),
        ),
      ),
    ),
    { initialValue: { data: null as PowerrankingResponse | null, loading: true, error: null as string | null } },
  );

  response = computed(() => this.state().data);
  loading  = computed(() => this.state().loading);
  error    = computed(() => this.state().error);
  locked   = computed(() => this.response()?.locked ?? false);

  // Admin-Vorschau der Reveal-Ansicht (Tabelle + alle bisherigen Tipps) schon während der
  // laufenden Tippphase — eigener, separat geladener Zustand, der nichts an `response()`
  // (dem echten Sperrstatus) ändert.
  private previewData    = signal<PowerrankingResponse | null>(null);
  previewLoading = signal(false);
  showingPreview = signal(false);

  openPreview(): void {
    this.showingPreview.set(true);
    if (this.previewData() || this.previewLoading()) return;
    this.previewLoading.set(true);
    this.api.get<PowerrankingResponse>('powerranking?preview=1').subscribe({
      next: data => { this.previewData.set(data); this.previewLoading.set(false); },
      error: () => { this.previewLoading.set(false); this.showingPreview.set(false); },
    });
  }

  closePreview(): void {
    this.showingPreview.set(false);
  }

  // Zeigt die Reveal-Ansicht entweder weil wirklich gesperrt, oder weil ein Admin sich die
  // Vorschau angesehen hat; Quelle der Reveal-Daten entsprechend `response()` oder `previewData()`.
  showReveal = computed(() => this.locked() || this.showingPreview());
  private revealData = computed(() => this.locked() ? this.response() : this.previewData());

  // Team-Info-Map aus der Reveal-Antwort — genug, um Trikot/Name/Verein in jedem Tipp-Board
  // nachzuschlagen, ohne einen zweiten Request pro Board zu brauchen
  private teamInfoById = computed(() => {
    const map = new Map<string, PowerrankingStanding>();
    for (const s of this.revealData()?.standings ?? []) map.set(s.team_id, s);
    return map;
  });

  teamInfo(teamId: string): PowerrankingStanding | undefined {
    return this.teamInfoById().get(teamId);
  }

  standings = computed(() => this.revealData()?.standings ?? []);

  entries = computed(() =>
    (this.revealData()?.entries ?? []).map(e => ({
      ...e,
      picks: [...e.picks].sort((a, b) => a.predicted_position - b.predicted_position).map(PowerrankingPick.from),
    })),
  );

  // Eigener Eintrag zuerst, Rest in der vom Server gelieferten Reihenfolge (nach total_deviation)
  sortedEntries = computed(() => {
    const list = this.entries();
    const ownIndex = list.findIndex(e => e.manager_id === this.myManagerId);
    if (ownIndex <= 0) return list;
    const own = list[ownIndex];
    return [own, ...list.slice(0, ownIndex), ...list.slice(ownIndex + 1)];
  });

  expandedManagerId = signal<string | null>(null);
  toggleExpanded(managerId: string): void {
    this.expandedManagerId.set(this.expandedManagerId() === managerId ? null : managerId);
  }

  // Zustand A: eigener editierbarer Tipp — Team-Roster nur laden, solange ungesperrt
  private teamsState = toSignal(
    toObservable(this.response).pipe(
      switchMap(r => {
        if (!r || r.locked) return of({ data: [] as LigaTeamLite[], loading: false });
        return this.api.get<LigaTeamLite[]>(`team?season_id=${r.season_id}`).pipe(
          map(data => ({ data, loading: false })),
          catchError(() => of({ data: [] as LigaTeamLite[], loading: false })),
        );
      }),
    ),
    { initialValue: { data: [] as LigaTeamLite[], loading: true } },
  );

  teamsLoading = computed(() => this.teamsState().loading);

  order = signal<LigaTeamLite[]>([]);
  private orderInitialized = false;

  constructor() {
    // Eigenes Board standardmäßig aufgeklappt, sobald die Reveal-Daten da sind
    effect(() => {
      const own = this.entries().find(e => e.manager_id === this.myManagerId);
      if (own && this.expandedManagerId() === null) this.expandedManagerId.set(own.manager_id);
    });

    // Editierbare Reihenfolge einmalig aus my_picks (oder Team-Roster als Default) aufbauen —
    // orderInitialized verhindert, dass ein Refetch (z.B. nach fehlgeschlagenem Save) den
    // laufenden Umsortier-Stand des Nutzers überschreibt
    effect(() => {
      const r     = this.response();
      const teams = this.teamsState().data;
      if (!r || r.locked || teams.length === 0 || this.orderInitialized) return;

      const byId    = new Map(teams.map(t => [t.id, t]));
      const myPicks = r.my_picks ?? [];
      const ordered = myPicks.length === teams.length
        ? [...myPicks].sort((a, b) => a.position - b.position).map(p => byId.get(p.team_id)).filter((t): t is LigaTeamLite => !!t)
        : teams;

      this.order.set(ordered);
      this.orderInitialized = true;
    });
  }

  drop(event: CdkDragDrop<LigaTeamLite[]>): void {
    const arr = [...this.order()];
    moveItemInArray(arr, event.previousIndex, event.currentIndex);
    this.order.set(arr);
  }

  moveUp(i: number): void {
    if (i <= 0) return;
    const arr = [...this.order()];
    moveItemInArray(arr, i, i - 1);
    this.order.set(arr);
  }

  moveDown(i: number): void {
    const arr = this.order();
    if (i >= arr.length - 1) return;
    const copy = [...arr];
    moveItemInArray(copy, i, i + 1);
    this.order.set(copy);
  }

  saving = signal(false);
  saveMessage = signal<string | null>(null);
  saveError = signal(false);

  save(): void {
    const r = this.response();
    if (!r || this.order().length === 0) return;

    this.saving.set(true);
    this.saveMessage.set(null);
    const picks = this.order().map((t, i) => ({ team_id: t.id, position: i + 1 }));

    this.api.post<{ status: boolean; message?: string }>('powerranking', { season_id: r.season_id, picks }).subscribe({
      next: res => {
        this.saving.set(false);
        if (res.status) {
          this.saveError.set(false);
          this.saveMessage.set('Tipp gespeichert.');
        } else {
          this.saveError.set(true);
          this.saveMessage.set(res.message ?? 'Fehler beim Speichern.');
          this.reload();
        }
      },
      error: () => {
        this.saving.set(false);
        this.saveError.set(true);
        this.saveMessage.set('Fehler beim Speichern — Tippphase evtl. bereits beendet.');
        this.reload();
      },
    });
  }

  teamLogoUrl(seasonId: string, teamId: string): string {
    return `${environment.imageApiUrl}/team/${seasonId}/${teamId}.png`;
  }

  private logoErrors = new Set<string>();
  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  deltaSymbol(predicted: number, actual: number | null): string {
    if (actual === null) return '';
    if (actual === predicted) return '=';
    return actual < predicted ? '↑' : '↓';
  }
}
