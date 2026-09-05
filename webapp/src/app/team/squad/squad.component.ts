import { Component, computed, effect, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, forkJoin, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

const CONSTRAINTS: Record<string, { min: number; max: number }> = {
  GOALKEEPER: { min: 1, max: 2 },
  DEFENDER:   { min: 5, max: 6 },
  MIDFIELDER: { min: 5, max: 6 },
  FORWARD:    { min: 3, max: 4 },
};

const POSITIONS = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];

@Component({
  selector: 'app-squad',
  standalone: false,
  templateUrl: './squad.component.html',
  styleUrl: './squad.component.scss'
})
export class SquadComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);
  private cache = inject(DataCacheService);

  constructor() {
    this.cache.ensureLeague();
    this.cache.ensureDivisions();

    effect(() => {
      const id = this.teamId();
      const stored = id ? this.readNotesStore()[id] ?? '' : '';
      this.note.set(stored);
      this.savedNote.set(stored);
    });
  }

  private id$ = this.route.parent!.paramMap.pipe(map(p => p.get('id')!));
  private teamId = toSignal(this.route.parent!.paramMap.pipe(map(p => p.get('id'))), { initialValue: null as string | null });

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        forkJoin({
          squad:  this.api.get<any>(`player_in_team?team_id=${id}&include_former=1`),
          offers: this.api.get<any>(`offer?team_id=${id}`).pipe(
            catchError(() => of({ offers: [] as any[], pending_sum: 0 }))
          ),
        }).pipe(
          map(({ squad, offers }) => ({
            current:       squad.current        as any[],
            former:        squad.former         as any[],
            draftedSquad:  squad.drafted_squad   as any[],
            pendingOffers: (offers.offers as any[]).filter(o => o.status === 'pending'),
            loading: false,
            error: null as string | null,
          })),
          startWith({ current: [] as any[], former: [] as any[], draftedSquad: [] as any[], pendingOffers: [] as any[], loading: true, error: null as string | null }),
          catchError(() => of({ current: [] as any[], former: [] as any[], draftedSquad: [] as any[], pendingOffers: [] as any[], loading: false, error: 'Fehler beim Laden' }))
        )
      )
    ),
    { initialValue: { current: [] as any[], former: [] as any[], draftedSquad: [] as any[], pendingOffers: [] as any[], loading: true, error: null as string | null } }
  );

  players       = computed(() => this.state().current);
  former        = computed(() => this.state().former);
  draftedSquad  = computed(() => this.state().draftedSquad);
  pendingOffers = computed(() => this.state().pendingOffers);
  loading       = computed(() => this.state().loading);
  error         = computed(() => this.state().error);

  playerCount      = computed(() => this.players().length);
  totalMarketValue = computed(() => this.players().reduce((sum, p) => sum + this.marketValue(p), 0));
  avgMarketValue   = computed(() => this.playerCount() > 0 ? this.totalMarketValue() / this.playerCount() : 0);

  positionWarnings = computed(() => this.positionStats().filter(s => s.count < s.min));

  private pendingCountByPosition = computed(() => {
    const counts: Record<string, number> = {};
    for (const o of this.pendingOffers()) {
      if (o.position) counts[o.position] = (counts[o.position] ?? 0) + 1;
    }
    return counts;
  });

  positionStats = computed(() => {
    const counts: Record<string, number> = {};
    const pending = this.pendingCountByPosition();
    for (const pos of POSITIONS) counts[pos] = 0;
    for (const p of this.players()) {
      if (p.position && counts[p.position] !== undefined) counts[p.position]++;
    }
    return POSITIONS.map(pos => {
      const { min, max } = CONSTRAINTS[pos];
      const count = counts[pos];
      const pendingCount = pending[pos] ?? 0;
      return {
        position: pos,
        count,
        min,
        max,
        bubbles: Array.from({ length: max }, (_, i) => ({
          filled:   i < count,
          pending:  i >= count && i < count + pendingCount,
          required: i >= count + pendingCount && i < min,
          isMin:    i < min,
        })),
      };
    });
  });

  positionLabel(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'TOR',
      DEFENDER:   'ABW',
      MIDFIELDER: 'MIT',
      FORWARD:    'STU',
    };
    return map[pos] ?? pos;
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

  marketValue(p: any): number {
    return Number(p.price ?? 0) + Number(p.points ?? 0) * this.cache.pointsBonus();
  }

  photoUrl(p: any): string | null {
    if (!p.photo_uploaded) return null;
    return `https://img.die-bestesten.de/player/${p.season_id}/${p.id}.png`;
  }

  clubLogoUrl(p: any): string {
    if (!p.current_club_id || !p.club_logo_uploaded) return 'img/placeholders/club.png';
    return `https://img.die-bestesten.de/club/${p.current_club_id}.png`;
  }

  formatPrice(price: number | null): string {
    if (price == null) return '—';
    // 2 Nachkommastellen zwingend nötig, nicht 1: der Marktwert steigt pro Saisonpunkt um
    // 20.000 € (division.points_bonus), also in 0,02-Mio-Schritten — mit nur 1 Nachkommastelle
    // würden z.B. 1,82 Mio und 1,84 Mio beide auf "1,8 Mio" gerundet und wären ununterscheidbar.
    if (price >= 1_000_000) return (price / 1_000_000).toFixed(2).replace('.', ',') + ' Mio. €';
    if (price >= 1_000)     return (price / 1_000).toFixed(0) + ' Tsd. €';
    return price.toLocaleString('de-DE') + ' €';
  }

  private readonly NOTES_STORAGE_KEY = 'team-notes';

  note = signal('');
  private savedNote = signal('');
  hasUnsavedChanges = computed(() => this.note() !== this.savedNote());

  private readNotesStore(): Record<string, string> {
    try {
      const raw = localStorage.getItem(this.NOTES_STORAGE_KEY);
      return raw ? JSON.parse(raw) : {};
    } catch { return {}; }
  }

  private writeNotesStore(store: Record<string, string>): void {
    try { localStorage.setItem(this.NOTES_STORAGE_KEY, JSON.stringify(store)); }
    catch { /* ignore */ }
  }

  onNoteInput(value: string): void {
    this.note.set(value);
  }

  onNoteSaveClick(): void {
    const id = this.teamId();
    if (!id) return;
    const value = this.note();
    const store = this.readNotesStore();
    if (value.trim() === '') delete store[id]; else store[id] = value;
    this.writeNotesStore(store);
    this.savedNote.set(value);
  }
}
