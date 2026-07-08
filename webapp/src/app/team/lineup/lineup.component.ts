import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal, toObservable, takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CdkDragMove } from '@angular/cdk/drag-drop';
import { catchError, combineLatest, filter, interval, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

interface LineupPlayer {
  id: string;
  displayname: string;
  position: string;
  position_index: number | null;
  season_id: string;
  nominated: boolean;
  grade: any;
  points: any;
  goals: number;
  assists: number;
  clean_sheet: number;
  sds: number;
  red_card: number;
  yellow_red_card: number;
  participation: string | null;
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
  private route = inject(ActivatedRoute);

  private teamId$ = this.route.parent!.paramMap.pipe(map(p => p.get('id')!));

  selectedMatchdayId = signal<string | null>(
    this.route.snapshot.queryParamMap.get('matchday_id')
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
      .sort((a, b) => (this.posOrder[a.position] ?? 9) - (this.posOrder[b.position] ?? 9))
  );

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

  isEditable = computed(() => {
    const md = this.matchday();
    if (!md) return false;
    const now = new Date();
    return now.toISOString().slice(0, 10) >= md.start_date && now < new Date(md.kickoff_date);
  });

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

  getPlayersByPosition(pos: string): LineupPlayer[] {
    return this.nominated().filter(p => p.position === pos);
  }

  formationLabel(f: number[]): string {
    return `${f[1]}${f[2]}${f[3]}`;
  }

  isFormationActive(f: number[]): boolean {
    const cur = this.formation();
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
    if (!this.showBreakdown()) return;
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

  onDragMove(event: CdkDragMove, currentPlayer: LineupPlayer): void {
    const { x, y } = event.pointerPosition;
    const hovered = this.lineupPlayers().find(p => {
      if (p.id === currentPlayer.id) return false;
      const el = document.getElementById(p.id);
      if (!el) return false;
      const rect = el.getBoundingClientRect();
      return x > rect.left && x < rect.right && y > rect.top && y < rect.bottom;
    });
    this.hoveredPlayer.set(hovered ?? null);
  }

  onDragReleased(event: any, playerType: 'nominated' | 'bench'): void {
    const draggedId = event.source.element.nativeElement.id as string;
    const dragged   = this.lineupPlayers().find(p => p.id === draggedId);
    const hovered   = this.hoveredPlayer();

    if (dragged && hovered) {
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

    (event.source as any)._dragRef.reset();
  }

  onDragEnd(): void {
    this.hoveredPlayer.set(null);
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
      error: () => this.saving.set(false),
    });
  }
}
