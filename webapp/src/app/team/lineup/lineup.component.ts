import { Component, ElementRef, ViewChild, computed, effect, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal, toObservable, takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CdkDragMove } from '@angular/cdk/drag-drop';
import { catchError, combineLatest, filter, interval, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';

interface LineupPlayer {
  id: string;
  displayname: string;
  position: string;
  position_index: number | null;
  season_id: string;
  nominated: boolean;
  price: number;
  season_points: number;
  grade: any;
  points: any;
  goals: number;
  assists: number;
  clean_sheet: number;
  sds: number;
  red_card: number;
  yellow_red_card: number;
  participation: string | null;
  has_rating: boolean;
  photo_uploaded: boolean;
}

@Component({
  selector: 'app-lineup',
  standalone: false,
  templateUrl: './lineup.component.html',
  styleUrl: './lineup.component.scss'
})
export class LineupComponent {
  private api   = inject(ApiService);
  private auth  = inject(AuthService);
  private route = inject(ActivatedRoute);

  private teamId$ = this.route.parent!.paramMap.pipe(map(p => p.get('id')!));

  selectedMatchdayId = signal<string | null>(
    this.route.snapshot.queryParamMap.get('matchday_id')
  );

  // Whose team this is — a foreign team's lineup is viewable (e.g. via /liga/teams), but never
  // editable: the API already rejects a PATCH with "Not your team", this just keeps the UI from
  // suggesting otherwise (drag&drop that silently gets rejected on save is confusing).
  isOwnTeam = toSignal(
    this.teamId$.pipe(
      switchMap(id => this.api.get<any>(`team/${id}`).pipe(catchError(() => of(null)))),
      map(team => team?.manager_id === this.auth.getManagerId())
    ),
    { initialValue: false }
  );

  private state = toSignal(
    combineLatest([
      this.teamId$,
      toObservable(this.selectedMatchdayId),
    ]).pipe(
      switchMap(([teamId, matchdayId]) => {
        const url = matchdayId
          ? `team_lineup?team_id=${teamId}&matchday_id=${matchdayId}`
          : `team_lineup?team_id=${teamId}`;
        return this.api.get<any>(url).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as any, loading: true, error: null as string | null }),
          catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' }))
        );
      })
    ),
    { initialValue: { data: null as any, loading: true, error: null as string | null } }
  );

  lineupPlayers = signal<LineupPlayer[]>([]);

  @ViewChild('benchListEl') benchListEl?: ElementRef<HTMLElement>;
  benchCanScrollLeft  = signal(false);
  benchCanScrollRight = signal(false);

  constructor() {
    toObservable(this.state).pipe(
      filter(s => !s.loading),
      takeUntilDestroyed()
    ).subscribe(s => {
      if (s.data) {
        const nominated = (s.data.nominated ?? []).map((p: any) => ({ ...p, nominated: true }));
        const bench     = (s.data.bench ?? []).map((p: any) => ({ ...p, nominated: false }));
        this.lineupPlayers.set([...nominated, ...bench]);
      } else {
        this.lineupPlayers.set([]);
      }
    });

    // Re-check the arrow states whenever the bench contents change (bought/sold players,
    // a bench<->field swap) — queued so the list's scrollWidth reflects the updated DOM.
    effect(() => {
      this.bench();
      queueMicrotask(() => this.onBenchScroll());
    });
  }

  matchday  = computed(() => this.state().data?.matchday  ?? null);
  matchdays = computed(() => (this.state().data?.matchdays ?? []) as any[]);
  loading   = computed(() => this.state().loading);
  error     = computed(() => this.state().error);

  private readonly posOrder: Record<string, number> = { GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3 };

  nominated = computed(() =>
    this.lineupPlayers()
      .filter(p => p.nominated)
      .sort((a, b) =>
        (this.posOrder[a.position] ?? 9) - (this.posOrder[b.position] ?? 9) ||
        (a.position_index ?? 99) - (b.position_index ?? 99)
      )
  );

  bench = computed(() =>
    this.lineupPlayers()
      .filter(p => !p.nominated)
      .sort((a, b) =>
        (this.posOrder[a.position] ?? 9) - (this.posOrder[b.position] ?? 9) ||
        (b.season_points ?? 0) - (a.season_points ?? 0) ||
        (Number(b.price) || 0) - (Number(a.price) || 0)
      )
  );

  // Mobile bench row: stepped via arrow buttons instead of a finger-drag scroll, which would
  // otherwise fight with dragging a chip onto the field. Step size mirrors .bench-player's
  // mobile width (56px) + .mobile-bench__list gap (8px) in lineup.component.scss.
  private readonly benchStepPlayers = 3;
  private readonly benchChipStep = 64;

  shiftBench(direction: 1 | -1): void {
    this.benchListEl?.nativeElement.scrollBy({
      left: direction * this.benchStepPlayers * this.benchChipStep,
      behavior: 'smooth',
    });
  }

  onBenchScroll(): void {
    const el = this.benchListEl?.nativeElement;
    if (!el) return;
    this.benchCanScrollLeft.set(el.scrollLeft > 4);
    this.benchCanScrollRight.set(el.scrollLeft < el.scrollWidth - el.clientWidth - 4);
  }

  points    = computed(() => {
    const lp = this.lineupPlayers();
    if (!lp.length) return null;
    return lp.filter(p => p.nominated).reduce((s, p) => s + (p.points ?? 0), 0);
  });
  maxPoints = computed(() => {
    const lp = this.lineupPlayers();
    if (!lp.length) return null;
    return lp.reduce((s, p) => s + (p.points ?? 0), 0);
  });

  formation = computed(() => {
    const n = this.nominated();
    return [
      n.filter(p => p.position === 'GOALKEEPER').length,
      n.filter(p => p.position === 'DEFENDER').length,
      n.filter(p => p.position === 'MIDFIELDER').length,
      n.filter(p => p.position === 'FORWARD').length,
    ];
  });

  // A saved lineup is always either a complete valid XI (11 nominated) or a still-reachable
  // partial build (see isReachableFormation) — PATCH rejects anything else. For a foreign
  // viewer, a partial lineup is either mid-build or gapped by a sale (see POST /sell: it only
  // clears the sold player's own entry, leaving the rest nominated instead of a bank reset) —
  // either way it's not something a stranger should see player-by-player; the owner still sees
  // it as-is (see canEdit's empty-slot bubbles) to fix it themselves.
  isLineupComplete = computed(() => this.nominated().length === 11);
  showInvalidLineupHint = computed(() => !this.isOwnTeam() && !this.isLineupComplete());

  isEditable = computed(() => {
    const md = this.matchday();
    if (!md) return false;
    const now = new Date();
    return now.toISOString().slice(0, 10) >= md.start_date && now < new Date(md.kickoff_date);
  });

  // Gates all actual editing UI (drag&drop, formation picker, empty slots) — isEditable() alone
  // only checks the matchday's time window and stays true for a foreign team's lineup, which
  // used to make players there draggable even though the API always rejects the save.
  canEdit = computed(() => this.isEditable() && this.isOwnTeam());

  private tick = toSignal(interval(1000), { initialValue: 0 });

  countdown = computed((): string | null => {
    this.tick();
    const md = this.matchday();
    if (!md || !this.isEditable()) return null;
    const diff = new Date(md.kickoff_date).getTime() - Date.now();
    if (diff <= 0) return null;
    const d = Math.floor(diff / 86_400_000);
    const h = Math.floor((diff % 86_400_000) / 3_600_000);
    const m = Math.floor((diff % 3_600_000) / 60_000);
    const s = Math.floor((diff % 60_000) / 1_000);
    return [d > 0 ? `${d}T` : null, `${h}H`, `${String(m).padStart(2,'0')}M`, `${String(s).padStart(2,'0')}S`]
      .filter(Boolean).join(' ');
  });

  hoveredPlayer  = signal<LineupPlayer | null>(null);
  formationError = signal<string | null>(null);
  saving         = signal(false);

  tooltipPlayer = signal<LineupPlayer | null>(null);
  tooltipPos    = signal<{ top: number; left: number } | null>(null);

  showBreakdown = computed(() => {
    const md = this.matchday();
    if (!md) return false;
    return new Date() >= new Date(md.kickoff_date);
  });

  readonly validFormations = [
    [1,3,4,3],[1,3,5,2],[1,4,3,3],[1,4,4,2],[1,4,5,1],[1,5,3,2],[1,5,4,1],
  ];

  readonly pitchPositions = ['FORWARD', 'MIDFIELDER', 'DEFENDER', 'GOALKEEPER'];

  // Classic shape used to complete a partial/empty lineup into a full valid XI — only used
  // as a tie-breaker below, never shown as-is if it would conflict with an already-nominated
  // position count.
  private readonly fallbackFormation = [1, 4, 4, 2];

  // While nothing is nominated yet, a click on a formation chip (see selectFormation()) picks
  // which empty-slot shape is scaffolded — otherwise the closest-to-4-4-2 default below applies.
  private manualEmptyFormation = signal<number[] | null>(null);

  // Baseline slot layout so the field always shows enough empty placeholders to build a
  // complete, valid lineup from scratch (e.g. after a skipped matchday left everyone on the
  // bench) — without these, dropping a bench player would have nothing to swap with and
  // silently do nothing. Picks, among the 7 valid formations that are still reachable from
  // the current selection (i.e. >= the actually nominated count in every position), the one
  // closest to the classic 4-4-2 default (or the manually picked one, if nothing is nominated
  // yet). A fully/validly nominated lineup always matches itself exactly (0 excess anywhere,
  // e.g. 4-3-3 only ever shows 3 midfielder slots); an empty or partial lineup completes
  // towards a coherent 11-player formation instead of the per-position minimum across all
  // formations, which isn't itself a valid formation and used to show only 8 slots total
  // (e.g. an invalid "3-3-1").
  private targetFormation = computed<number[]>(() => {
    const cur = this.formation();
    if (cur.every(v => v === 0)) {
      const manual = this.manualEmptyFormation();
      if (manual) return manual;
    }
    const compatible = this.validFormations.filter(f => f.every((v, i) => v >= cur[i]));
    if (compatible.length === 0) return cur;
    return compatible.reduce((best, f) =>
      this.formationDistance(f) < this.formationDistance(best) ? f : best
    );
  });

  private formationDistance(f: number[]): number {
    return f.reduce((sum, v, i) => sum + Math.abs(v - this.fallbackFormation[i]), 0);
  }

  // Only meaningful as long as nothing is nominated yet — picking a starting shape for an
  // established lineup would just get silently overridden by the closest-match logic above,
  // since a non-empty selection ignores manualEmptyFormation entirely.
  selectFormation(f: number[]): void {
    if (!this.canEdit() || this.nominated().length > 0) return;
    this.manualEmptyFormation.set(f);
  }

  // Mobile formation <select> only has the label string to work with (native <option value>).
  onFormationSelect(label: string): void {
    const f = this.validFormations.find(f => this.formationLabel(f) === label);
    if (f) this.selectFormation(f);
  }

  // True once all slots of one of the 7 formations are actually filled (11 nominated), not
  // just "reachable" mid-build — used to color the mobile formation box green vs. red.
  isFormationValid(): boolean {
    const cur = this.formation();
    return this.validFormations.some(f => f.every((v, i) => v === cur[i]));
  }

  getPlayersByPosition(pos: string): LineupPlayer[] {
    return this.nominated().filter(p => p.position === pos);
  }

  emptySlotIndices(pos: string): number[] {
    const actual = this.getPlayersByPosition(pos).length;
    const total  = Math.max(actual, this.targetFormation()[this.posOrder[pos] ?? -1] ?? 0);
    return this.range(total - actual).map((_, i) => actual + i);
  }

  formationLabel(f: number[]): string {
    return `${f[1]}${f[2]}${f[3]}`;
  }

  // True if some valid formation dominates the given per-position counts in every position —
  // i.e. the counts are still reachable towards a full valid XI. Same predicate the backend
  // applies as its own independent sanity check on save.
  private isReachableFormation(counts: number[]): boolean {
    return this.validFormations.some(f => f.every((v, i) => v >= counts[i]));
  }

  isFormationActive(f: number[]): boolean {
    // While nothing is nominated, formation() is [0,0,0,0] — highlight the scaffolded
    // baseline instead, so the chip the empty slots are currently shaped after stands out.
    const cur = this.nominated().length === 0 ? this.targetFormation() : this.formation();
    return f[0] === cur[0] && f[1] === cur[1] && f[2] === cur[2] && f[3] === cur[3];
  }

  pointsPercent(): number {
    const p = this.points(), max = this.maxPoints();
    if (!max || max <= 0) return 0;
    return Math.min(100, Math.round(((p ?? 0) / max) * 100));
  }

  positionColor(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'var(--position-goalkeeper)',
      DEFENDER:   'var(--position-defender)',
      MIDFIELDER: 'var(--position-midfielder)',
      FORWARD:    'var(--position-forward)',
    };
    return map[pos] ?? 'transparent';
  }

  range(n: number): number[] { return Array.from({ length: n }, (_, i) => i); }

  gradeInt(grade: any): number {
    return Math.round(+grade * 10);
  }

  participationLabel(p: string | null): string {
    if (p === 'starting')   return 'Startelf';
    if (p === 'substitute') return 'Eingewechselt';
    return 'Kein Einsatz';
  }

  participationClass(p: string | null): string {
    if (p === 'starting')   return 'bench-player__status--starting';
    if (p === 'substitute') return 'bench-player__status--sub';
    return 'bench-player__status--none';
  }

  photoUrl(p: any): string | null {
    if (!p.photo_uploaded || !p.season_id) return null;
    return `https://img.die-bestesten.de/player/${p.season_id}/${p.id}.png`;
  }

  photoErrors = new Set<string>();
  onPhotoError(id: string) { this.photoErrors.add(id); }

  // Tooltip

  onBadgeEnter(event: MouseEvent, p: LineupPlayer): void {
    if (!this.showBreakdown() || p.points === null) return;
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.tooltipPlayer.set(p);
    this.tooltipPos.set({ top: rect.top, left: rect.left + rect.width / 2 });
  }

  onBadgeLeave(): void {
    this.tooltipPlayer.set(null);
    this.tooltipPos.set(null);
  }

  breakdownRows(p: LineupPlayer): Array<{ label: string; pts: number }> {
    const rows: Array<{ label: string; pts: number }> = [];

    if (p.participation === 'starting') {
      rows.push({ label: 'Startelf', pts: 2 });
    } else if (p.participation === 'substitute') {
      rows.push({ label: 'Eingewechselt', pts: 1 });
    } else if (this.matchday()?.completed) {
      rows.push({ label: 'Kein Einsatz', pts: 0 });
    }

    if (p.grade != null) {
      const gradePts = Math.round((3.5 - Number(p.grade)) * 4);
      rows.push({ label: `Note ${Number(p.grade).toFixed(1).replace('.', ',')}`, pts: gradePts });
    }

    if (p.sds) {
      rows.push({ label: 'Spieler des Spiels', pts: 3 });
    }

    if (p.goals > 0) {
      const perGoal: Record<string, number> = { GOALKEEPER: 6, DEFENDER: 5, MIDFIELDER: 4, FORWARD: 3 };
      rows.push({ label: `Tore (${p.goals}×)`, pts: p.goals * (perGoal[p.position] ?? 3) });
    }

    if (p.assists > 0) {
      rows.push({ label: `Vorlagen (${p.assists}×)`, pts: p.assists });
    }

    if (p.clean_sheet && p.position === 'GOALKEEPER') {
      rows.push({ label: 'Zu Null', pts: 2 });
    }

    if (p.red_card) {
      rows.push({ label: 'Rote Karte', pts: -6 });
    }

    if (p.yellow_red_card) {
      rows.push({ label: 'Gelb-Rote Karte', pts: -3 });
    }

    return rows;
  }

  // Drag & drop

  hoveredSlot = signal<{ pos: string; index: number } | null>(null);

  onDragMove(event: CdkDragMove, currentPlayer: LineupPlayer): void {
    const { x, y } = event.pointerPosition;
    // Only on-field (nominated) players are valid hover targets — a bench player must never
    // register as "hovered" here, otherwise a bench→field swap can match another bench player
    // and nominate the dragged player without benching anyone in return (see onDragReleased).
    const hovered = this.lineupPlayers().filter(p => p.nominated).find(p => {
      if (p.id === currentPlayer.id) return false;
      const el = document.getElementById(p.id);
      if (!el) return false;
      const rect = el.getBoundingClientRect();
      return x > rect.left && x < rect.right && y > rect.top && y < rect.bottom;
    });
    this.hoveredPlayer.set(hovered ?? null);

    if (hovered) {
      this.hoveredSlot.set(null);
      return;
    }

    const slotEl = Array.from(document.querySelectorAll<HTMLElement>('.empty-slot')).find(el => {
      const rect = el.getBoundingClientRect();
      return x > rect.left && x < rect.right && y > rect.top && y < rect.bottom;
    });
    this.hoveredSlot.set(
      slotEl ? { pos: slotEl.dataset['pos']!, index: Number(slotEl.dataset['index']) } : null
    );
  }

  onDragReleased(event: any, playerType: 'nominated' | 'bench'): void {
    const draggedId = event.source.element.nativeElement.id as string;
    const dragged   = this.lineupPlayers().find(p => p.id === draggedId);
    const hovered   = this.hoveredPlayer();
    const slot      = this.hoveredSlot();

    if (dragged && !hovered && slot && dragged.position === slot.pos) {
      // Dropped on an empty placeholder — fills it directly, nobody needs to move to the bench.
      // Guarded even though emptySlotIndices() is already bounded by targetFormation(): a
      // last line of defense against ever writing an unreachable formation from here.
      const posIdx: Record<string, number> = { GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3 };
      const newFormation = [...this.formation()];
      if (!dragged.nominated) newFormation[posIdx[dragged.position]] += 1;

      if (this.isReachableFormation(newFormation)) {
        this.lineupPlayers.update(ps => ps.map(p =>
          p.id === dragged.id ? { ...p, nominated: true, position_index: slot.index } : p
        ));
        this.normalizePositionIndexes();
        this.saveLineup();
      } else {
        this.formationError.set('Keine gültige Formation mehr möglich');
        setTimeout(() => this.formationError.set(null), 2500);
      }
    } else if (dragged && hovered) {
      if (dragged.position === hovered.position) {
        if (playerType === 'nominated') {
          // Reorder on field: swap position_index
          this.lineupPlayers.update(ps => ps.map(p => {
            if (p.id === dragged.id) return { ...p, position_index: hovered.position_index };
            if (p.id === hovered.id) return { ...p, position_index: dragged.position_index };
            return p;
          }));
        } else {
          // Bench → field, same position: direct swap
          this.lineupPlayers.update(ps => ps.map(p => {
            if (p.id === dragged.id) return { ...p, nominated: true, position_index: hovered.position_index };
            if (p.id === hovered.id) return { ...p, nominated: false, position_index: null };
            return p;
          }));
        }
        this.normalizePositionIndexes();
        this.saveLineup();
      } else if (playerType === 'bench') {
        // Bench → field, different position: validate formation
        const posIdx: Record<string, number> = { GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3 };
        const newFormation = [...this.formation()];
        newFormation[posIdx[dragged.position]] += 1;
        newFormation[posIdx[hovered.position]] -= 1;

        if (this.validFormations.some(f => f.every((v, i) => v === newFormation[i]))) {
          this.lineupPlayers.update(ps => ps.map(p => {
            if (p.id === dragged.id) return { ...p, nominated: true, position_index: hovered.position_index };
            if (p.id === hovered.id) return { ...p, nominated: false, position_index: null };
            return p;
          }));
          this.normalizePositionIndexes();
          this.saveLineup();
        } else {
          const label = `${newFormation[1]}${newFormation[2]}${newFormation[3]}`;
          this.formationError.set(`${label} ist keine erlaubte Formation`);
          setTimeout(() => this.formationError.set(null), 2500);
        }
      }
    }

    this.hoveredSlot.set(null);
    (event.source as any)._dragRef.reset();
  }

  onDragEnd(): void {
    this.hoveredPlayer.set(null);
    this.hoveredSlot.set(null);
  }

  private normalizePositionIndexes(): void {
    this.lineupPlayers.update(players => {
      const copy = players.map(p => ({ ...p }));
      ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'].forEach(pos => {
        copy
          .filter(p => p.nominated && p.position === pos)
          .sort((a, b) => (a.position_index ?? 99) - (b.position_index ?? 99))
          .forEach((p, i) => { p.position_index = i; });
      });
      copy.filter(p => !p.nominated).forEach(p => { p.position_index = null; });
      return copy;
    });
  }

  private saveLineup(): void {
    const md     = this.matchday();
    const teamId = this.route.parent!.snapshot.paramMap.get('id');
    if (!md || !teamId) return;

    this.saving.set(true);
    this.api.patch<any>('team_lineup', {
      team_id:     teamId,
      matchday_id: md.id,
      players: this.lineupPlayers().map(p => ({
        player_id:      p.id,
        nominated:      p.nominated,
        position_index: p.position_index,
      })),
    }).subscribe({
      next:  () => this.saving.set(false),
      error: (err) => {
        this.saving.set(false);
        if (err?.status === 422) {
          // Backend rejected an unreachable formation the client-side guards missed — surface it
          // instead of failing silently; the field itself stays as-is until the next reload.
          this.formationError.set('Ungültige Formation');
          setTimeout(() => this.formationError.set(null), 2500);
        }
      },
    });
  }
}
