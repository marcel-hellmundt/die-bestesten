import { Component, computed, ElementRef, inject, signal, TemplateRef, ViewChild } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { Club, Stadium } from '../../core/models/club.model';
import { POSITION_COLOR, POSITION_LABEL } from '../../core/constants';

@Component({
  selector: 'app-club-detail',
  standalone: false,
  templateUrl: './club-detail.component.html',
  styleUrl: './club-detail.component.scss',
})
export class ClubDetailComponent {
  private api    = inject(ApiService);
  private auth   = inject(AuthService);
  private route  = inject(ActivatedRoute);
  private router = inject(Router);
  bottomSheet    = inject(BottomSheetService);

  navigate(path: any[]): void { this.router.navigate(path); }
  cache = inject(DataCacheService);

  isAdmin = computed(() => this.auth.isAdmin());

  private id$ = this.route.paramMap.pipe(map((p) => p.get('id')!));
  private reloadSeasons$ = new BehaviorSubject<void>(undefined);

  private clubState = toSignal(
    this.id$.pipe(
      switchMap((id) =>
        this.api.get<any>(`club/${id}`).pipe(
          map((data) => ({ data: Club.from(data), loading: false, error: null as string | null })),
          startWith({ data: null as Club | null, loading: true, error: null as string | null }),
          catchError(() =>
            of({ data: null as Club | null, loading: false, error: 'Fehler beim Laden' }),
          ),
        ),
      ),
    ),
  );

  private seasonsState = toSignal(
    this.id$.pipe(
      switchMap((id) =>
        this.reloadSeasons$.pipe(
          switchMap(() =>
            this.api.get<any[]>(`club_in_season?club_id=${id}`).pipe(
              map((data) => ({ data, loading: false, error: null as string | null })),
              startWith({ data: [] as any[], loading: true, error: null as string | null }),
              catchError(() =>
                of({ data: [] as any[], loading: false, error: 'Fehler beim Laden' }),
              ),
            ),
          ),
        ),
      ),
    ),
  );

  // Set right after a stadium is created — overlays the freshly created stadium onto the
  // loaded club without a network round-trip (same idea as logoBust for the logo upload).
  // Keyed by clubId so it doesn't leak onto a different club after navigating (the
  // component instance is reused when the route param changes, e.g. via search).
  private stadiumOverride = signal<{ clubId: string; stadium: Stadium } | null>(null);

  club = computed(() => {
    const c = this.clubState()?.data ?? null;
    const override = this.stadiumOverride();
    if (!c || !override || override.clubId !== c.id) return c;
    return new Club(c.id, c.country_id, c.name, c.short_name, c.logo_uploaded, override.stadium);
  });
  loading = computed(() => this.clubState()?.loading ?? true);
  error = computed(() => this.clubState()?.error ?? null);
  seasons = computed(() => this.seasonsState()?.data ?? []);
  seasonsLoading = computed(() => this.seasonsState()?.loading ?? true);

  availableSeasons = computed(() => {
    const usedIds = new Set(this.seasons().map((s: any) => s.season_id));
    return this.cache.seasons().filter((s) => !usedIds.has(s.id));
  });

  idCopied = signal(false);
  logoUploadState = signal<'idle' | 'loading' | 'error'>('idle');
  logoBust = signal<number | null>(null);

  @ViewChild('logoInput') logoInput!: ElementRef<HTMLInputElement>;

  onLogoClick(): void {
    this.logoInput.nativeElement.click();
  }

  onLogoFileSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const clubId = this.club()?.id;
    if (!clubId) return;

    this.logoUploadState.set('loading');
    this.api.uploadClubLogo(clubId, file).subscribe({
      next: () => {
        this.logoBust.set(Date.now());
        this.logoUploadState.set('idle');
      },
      error: () => {
        this.logoUploadState.set('error');
        setTimeout(() => this.logoUploadState.set('idle'), 2000);
      },
    });
    (event.target as HTMLInputElement).value = '';
  }

  copyId(id: string): void {
    navigator.clipboard.writeText(id).then(() => {
      this.idCopied.set(true);
      setTimeout(() => this.idCopied.set(false), 1500);
    });
  }

  // Edit state
  isEditing = signal(false);
  patchingId = signal<string | null>(null);
  patchError = signal<string | null>(null);

  // Add form state
  showAddForm = signal(false);
  newSeasonId = signal('');
  newDivisionId = signal('');
  newPosition = signal('');
  adding = signal(false);
  addError = signal<string | null>(null);

  toggleEdit(): void {
    this.isEditing.update((v) => !v);
    this.patchError.set(null);
  }

  onDivisionChange(row: any, event: Event): void {
    const division_id = (event.target as HTMLSelectElement).value;
    this.patch(row.id, { division_id, position: row.position ?? null });
  }

  onPositionChange(row: any, event: Event): void {
    const raw = (event.target as HTMLInputElement).value.trim();
    const position = raw === '' ? null : parseInt(raw, 10);
    this.patch(row.id, { division_id: row.division_id, position });
  }

  private patch(id: string, body: { division_id: string; position: number | null }): void {
    this.patchingId.set(id);
    this.patchError.set(null);
    this.api.patch(`club_in_season/${id}`, body).subscribe({
      next: () => {
        this.patchingId.set(null);
        this.reloadSeasons$.next();
      },
      error: () => {
        this.patchingId.set(null);
        this.patchError.set(id);
      },
    });
  }

  private smartDivision(seasonId: string): string {
    const existing = this.seasons().filter((e: any) => e.division_id && e.season_start);

    const mostFrequent = (): string => {
      if (!existing.length) return this.cache.divisions()[0]?.id ?? '';
      const counts = new Map<string, number>();
      for (const e of existing) counts.set(e.division_id, (counts.get(e.division_id) ?? 0) + 1);
      const [topId, topCount] = [...counts.entries()].sort((a, b) => b[1] - a[1])[0];
      return topCount / existing.length >= 0.5 ? topId : (this.cache.divisions()[0]?.id ?? '');
    };

    if (!existing.length) return mostFrequent();

    const target = this.cache.seasons().find((s) => s.id === seasonId);
    if (!target) return mostFrequent();

    const sorted = [...existing].sort((a, b) => a.season_start.localeCompare(b.season_start));
    const prev = sorted.filter((e: any) => e.season_start < target.start_date).at(-1) ?? null;
    const next = sorted.find((e: any) => e.season_start > target.start_date) ?? null;

    if (prev && next)
      return prev.division_id === next.division_id ? prev.division_id : mostFrequent();
    if (prev) return prev.division_id;
    if (next) return next.division_id;
    return mostFrequent();
  }

  @ViewChild('positionInput') positionInput!: ElementRef<HTMLInputElement>;

  openAddForm(): void {
    const firstSeason = this.availableSeasons()[0];
    this.newSeasonId.set(firstSeason?.id ?? '');
    this.newDivisionId.set(
      firstSeason ? this.smartDivision(firstSeason.id) : (this.cache.divisions()[0]?.id ?? ''),
    );
    this.newPosition.set('');
    this.addError.set(null);
    this.showAddForm.set(true);
    setTimeout(() => this.positionInput?.nativeElement.focus(), 0);
  }

  onNewSeasonChange(seasonId: string): void {
    this.newSeasonId.set(seasonId);
    this.newDivisionId.set(this.smartDivision(seasonId));
  }

  cancelAdd(): void {
    this.showAddForm.set(false);
    this.addError.set(null);
  }

  submitAdd(): void {
    const clubId = this.club()?.id;
    if (!clubId || !this.newSeasonId()) return;

    const raw = this.newPosition().trim();
    const position = raw === '' ? null : parseInt(raw, 10);

    this.adding.set(true);
    this.addError.set(null);
    this.api
      .post('club_in_season', {
        club_id: clubId,
        season_id: this.newSeasonId(),
        division_id: this.newDivisionId() || null,
        position,
      })
      .subscribe({
        next: () => {
          this.adding.set(false);
          this.showAddForm.set(false);
          this.reloadSeasons$.next();
        },
        error: (err: any) => {
          this.adding.set(false);
          this.addError.set(err?.error?.message ?? 'Fehler beim Speichern');
        },
      });
  }

  // Chart
  private readonly levelColors = [
    'var(--division-level-1)',
    'var(--division-level-2)',
    'var(--division-level-3)',
  ];

  divisionColor(divisionId: string): string {
    const div = this.cache.divisions().find((d) => d.id === divisionId);
    if (!div) return '#999999';
    return this.levelColors[(div.level - 1) % this.levelColors.length];
  }

  readonly chartW = 360;
  readonly chartH = 160;
  readonly padL = 28;
  readonly padR = 12;
  readonly padT = 12;
  readonly padB = 24;

  chartData = computed(() => {
    const divisions = this.cache.divisions();
    if (!divisions.length) return null;

    const entries = this.seasons()
      .filter((s) => s.position != null)
      .slice()
      .sort((a, b) => (a.season_start as string).localeCompare(b.season_start as string));

    if (entries.length < 2) return null;

    const usedDivIds = new Set(entries.map((e) => e.division_id));
    const sortedDivs = [...divisions]
      .filter((d) => usedDivIds.has(d.id))
      .sort((a, b) => a.level - b.level);
    const offsetMap = new Map<string, number>();
    let cumulative = 0;
    for (const div of sortedDivs) {
      offsetMap.set(div.id, cumulative);
      cumulative += div.seats;
    }
    const totalSeats = cumulative;

    const plotW = this.chartW - this.padL - this.padR;
    const plotH = this.chartH - this.padT - this.padB;

    // X position is driven by each season's index in the full chronological season list
    // (not by the entry's index among this club's entries), so a run of missing seasons
    // between two entries stretches the gap on the axis proportionally.
    const allSeasonsSorted = [...this.cache.seasons()].sort((a, b) =>
      a.start_date.localeCompare(b.start_date),
    );
    const seasonIndex = new Map(allSeasonsSorted.map((s, i) => [s.id, i]));
    const entryIndices = entries.map((e) => seasonIndex.get(e.season_id) ?? 0);
    const minIdx = Math.min(...entryIndices);
    const maxIdx = Math.max(...entryIndices);
    const idxRange = maxIdx - minIdx || 1;

    const toX = (idx: number) => this.padL + ((idx - minIdx) / idxRange) * plotW;
    const toY = (absPos: number) => this.padT + ((absPos - 1) / (totalSeats - 1)) * plotH;

    const points = entries.map((e, i) => {
      const offset = offsetMap.get(e.division_id) ?? 0;
      const absPos = offset + (e.position as number);
      return {
        x: toX(entryIndices[i]),
        y: toY(absPos),
        position: e.position as number,
        division: this.cache.divisionName(e.division_id),
        label: this.cache.seasonName(e.season_id),
        color: this.divisionColor(e.division_id),
      };
    });

    // Segments spanning one or more seasons with no entry for this club are drawn dashed,
    // so a missing season in between is visible instead of looking like a normal gap-free run.
    const segments = points.slice(1).map((p, i) => {
      const prev = points[i];
      return {
        d: `M${prev.x},${prev.y} L${p.x},${p.y}`,
        gap: entryIndices[i + 1] - entryIndices[i] > 1,
      };
    });

    const divLines = sortedDivs.slice(0, -1).map((_, i) => {
      const boundary = offsetMap.get(sortedDivs[i + 1].id)!;
      return { y: toY(boundary + 0.5), label: sortedDivs[i + 1].name };
    });

    const yTicks = [
      { y: toY(1), label: '1' },
      { y: toY(totalSeats), label: String(totalSeats) },
    ];

    const xLabels = [
      { x: points[0].x, label: points[0].label },
      { x: points[points.length - 1].x, label: points[points.length - 1].label },
    ];

    return { points, segments, yTicks, divLines, xLabels };
  });

  private squadState = toSignal(
    this.id$.pipe(
      switchMap((id) =>
        this.api.get<any[]>(`player?club_id=${id}`).pipe(
          map((data) => ({ data, loading: false })),
          startWith({ data: [] as any[], loading: true }),
          catchError(() => of({ data: [] as any[], loading: false })),
        ),
      ),
    ),
  );

  squad = computed(() => this.squadState()?.data ?? []);
  squadLoading = computed(() => this.squadState()?.loading ?? true);

  positionLabel(pos: string | null): string {
    return pos ? (POSITION_LABEL[pos] ?? pos) : '—';
  }

  positionColor(pos: string): string {
    return POSITION_COLOR[pos] ?? '#999';
  }

  // Stadium creation
  @ViewChild('stadiumSheet') stadiumSheet!: TemplateRef<any>;

  stadiumOfficialName = signal('');
  stadiumName         = signal('');
  stadiumCapacity     = signal('');
  stadiumLat          = signal('');
  stadiumLng          = signal('');
  stadiumFromDate     = signal('');
  stadiumSaving       = signal(false);
  stadiumError        = signal<string | null>(null);

  openStadiumForm(): void {
    this.stadiumOfficialName.set('');
    this.stadiumName.set('');
    this.stadiumCapacity.set('');
    this.stadiumLat.set('');
    this.stadiumLng.set('');
    this.stadiumFromDate.set(new Date().toISOString().slice(0, 10));
    this.stadiumError.set(null);
    this.bottomSheet.open(this.stadiumSheet, { title: 'Stadion anlegen' });
  }

  submitStadium(): void {
    const clubId = this.club()?.id;
    const officialName = this.stadiumOfficialName().trim();
    if (!clubId || !officialName) return;

    const body = {
      club_id: clubId,
      official_name: officialName,
      name: this.stadiumName().trim() || null,
      capacity: this.stadiumCapacity() ? parseInt(this.stadiumCapacity(), 10) : null,
      lat: this.stadiumLat() ? parseFloat(this.stadiumLat()) : null,
      lng: this.stadiumLng() ? parseFloat(this.stadiumLng()) : null,
      from_date: this.stadiumFromDate() || null,
    };

    this.stadiumSaving.set(true);
    this.stadiumError.set(null);
    this.api.post<{ id: string }>('stadium', body).subscribe({
      next: (res) => {
        this.stadiumOverride.set({
          clubId,
          stadium: {
            id: res.id,
            official_name: body.official_name,
            name: body.name,
            capacity: body.capacity,
            lat: body.lat,
            lng: body.lng,
          },
        });
        this.stadiumSaving.set(false);
        this.bottomSheet.close();
      },
      error: (err: any) => {
        this.stadiumSaving.set(false);
        this.stadiumError.set(err?.error?.message ?? 'Fehler beim Speichern');
      },
    });
  }

  constructor() {
    this.cache.ensureSeasons();
    this.cache.ensureDivisions();
  }
}
