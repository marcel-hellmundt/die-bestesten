import { Component, computed, inject, signal, TemplateRef, ViewChild } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { Player } from '../../core/models/player.model';
import { DataCacheService } from '../../core/data-cache.service';
import { AuthService } from '../../auth/auth.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { POSITION_LABEL } from '../../core/constants';

@Component({
  selector: 'app-data-player',
  standalone: false,
  templateUrl: './player.component.html',
  styleUrl: './player.component.scss'
})
export class PlayerDataComponent {
  private api    = inject(ApiService);
  private router = inject(Router);
  private route  = inject(ActivatedRoute);
  private auth   = inject(AuthService);
  bottomSheet    = inject(BottomSheetService);

  navigate(id: string): void { this.router.navigate([id], { relativeTo: this.route }); }
  cache = inject(DataCacheService);

  isMaintainer = computed(() => this.auth.isMaintainer());
  isAdmin      = computed(() => this.auth.isAdmin());

  private reload$ = new BehaviorSubject<void>(undefined);

  private state = toSignal(
    this.reload$.pipe(
      switchMap(() => this.api.get<any[]>('player').pipe(
        map(data => ({ data: data.map(Player.from), loading: false, error: null as string | null })),
        startWith({ data: [] as Player[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Player[], loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);

  searchQuery   = signal('');
  sortCol       = signal<'displayname' | 'total_points'>('total_points');
  sortDir       = signal<'asc' | 'desc'>('desc');

  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(i =>
      i.displayname.toLowerCase().includes(q) ||
      (i.first_name ?? '').toLowerCase().includes(q) ||
      (i.last_name  ?? '').toLowerCase().includes(q)
    );
  });

  sortedItems = computed(() => {
    const col = this.sortCol();
    const dir = this.sortDir();
    return [...this.filteredItems()].sort((a, b) => {
      const cmp = col === 'total_points'
        ? a.total_points - b.total_points
        : a.displayname.localeCompare(b.displayname);
      return dir === 'asc' ? cmp : -cmp;
    });
  });

  sort(col: 'displayname' | 'total_points'): void {
    if (this.sortCol() === col) {
      this.sortDir.update(d => d === 'asc' ? 'desc' : 'asc');
    } else {
      this.sortCol.set(col);
      this.sortDir.set(col === 'total_points' ? 'desc' : 'asc');
    }
  }

  bundesligaCount = toSignal(
    this.api.get<{ count: number }>('player_in_season/bundesliga_count').pipe(
      map(res => res.count),
      catchError(() => of(null))
    )
  );

  // Notfall-Anlage (Admin): Spieler hat bereits gespielt/Punkte geholt, ist aber noch nicht vom
  // externen CSV-Dienstleister erfasst — ohne ihn lässt sich der Spieltag für sein Team nicht mit
  // 11 Startern abschließen. Siehe POST /player/create_manual.
  @ViewChild('createPlayerSheet') createPlayerSheet!: TemplateRef<any>;

  readonly POSITION_LABEL = POSITION_LABEL;
  readonly positions: ('GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD')[] =
    ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];

  clubs = toSignal(
    this.api.get<{ id: string; name: string }[]>('club').pipe(
      map(list => [...list].sort((a, b) => a.name.localeCompare(b.name))),
      catchError(() => of([] as { id: string; name: string }[]))
    ),
    { initialValue: [] as { id: string; name: string }[] }
  );

  activeSeasonId = computed(() =>
    [...this.cache.seasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))[0]?.id ?? null
  );

  newFirstName   = signal('');
  newLastName    = signal('');
  newDisplayname = signal('');
  newPosition    = signal<'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD'>('MIDFIELDER');
  newClubId      = signal('');
  creatingPlayer = signal(false);
  createPlayerError = signal<string | null>(null);

  canCreatePlayer = computed(() =>
    this.newFirstName().trim() !== '' &&
    this.newLastName().trim()  !== '' &&
    this.newDisplayname().trim() !== '' &&
    !!this.newClubId() &&
    !!this.activeSeasonId()
  );

  openCreatePlayerForm(): void {
    this.newFirstName.set('');
    this.newLastName.set('');
    this.newDisplayname.set('');
    this.newPosition.set('MIDFIELDER');
    this.newClubId.set(this.clubs()[0]?.id ?? '');
    this.createPlayerError.set(null);
    this.bottomSheet.open(this.createPlayerSheet, { title: 'Spieler manuell anlegen' });
  }

  submitCreatePlayer(): void {
    const seasonId = this.activeSeasonId();
    if (!this.canCreatePlayer() || !seasonId || this.creatingPlayer()) return;

    this.creatingPlayer.set(true);
    this.createPlayerError.set(null);
    this.api.post<{ id: string }>('player/create_manual', {
      first_name:  this.newFirstName().trim(),
      last_name:   this.newLastName().trim(),
      displayname: this.newDisplayname().trim(),
      season_id:   seasonId,
      position:    this.newPosition(),
      club_id:     this.newClubId(),
    }).subscribe({
      next: ({ id }) => {
        this.creatingPlayer.set(false);
        this.bottomSheet.close();
        this.reload$.next();
        this.navigate(id);
      },
      error: (err: any) => {
        this.creatingPlayer.set(false);
        this.createPlayerError.set(err?.error?.message ?? 'Fehler beim Anlegen');
      },
    });
  }

  constructor() {
    this.cache.ensureLeague();
    this.cache.ensureDivisions();
    this.cache.ensureSeasons();
  }
}
