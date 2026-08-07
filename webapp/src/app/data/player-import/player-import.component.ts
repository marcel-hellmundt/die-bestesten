import { Component, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { PlayerImportRow } from '../../core/models/player-import-row.model';
import { MissingClubMember } from '../../core/models/missing-club-member.model';
import { POSITION_COLOR, POSITION_LABEL } from '../../core/constants';

interface ImportResult {
  created_count: number;
  skipped: { player_id: string; reason: string }[];
}

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

@Component({
  selector: 'app-data-player-import',
  standalone: false,
  templateUrl: './player-import.component.html',
  styleUrl: './player-import.component.scss',
})
export class PlayerImportDataComponent {
  private api = inject(ApiService);
  cache = inject(DataCacheService);

  readonly POSITION_LABEL = POSITION_LABEL;
  readonly POSITION_COLOR = POSITION_COLOR;

  divisions = this.cache.divisions;
  /** Dropdown display value on the confirm-division step (auto-detected, a fallback, or the user's pick). */
  divisionId = signal<string | null>(null);
  /** Dropdown selection on the confirm-division step — may differ from divisionId until confirmed. */
  pendingDivisionId = signal<string | null>(null);
  /** Division the currently loaded rows/missingPlayers actually reflect — null until a real (non-fallback) analysis ran. */
  private analyzedDivisionId = signal<string | null>(null);
  divisionCandidates = signal<{ division_id: string; count: number }[]>([]);
  divisionAutoDetected = signal(false);
  selectedFile = signal<File | null>(null);

  topCandidate = computed(() => this.divisionCandidates()[0] ?? null);

  step = signal<'upload' | 'confirm-division' | 'preview' | 'result'>('upload');

  uploading = signal(false);
  uploadError = signal<string | null>(null);

  rows = signal<PlayerImportRow[]>([]);
  missingPlayers = signal<MissingClubMember[]>([]);
  seasonId = signal<string | null>(null);
  seasonStartDate = signal<string | null>(null);
  divisionWarning = signal(false);
  divisionMismatchCount = signal(0);
  resolvedClubCount = signal(0);

  importing = signal(false);
  importResult = signal<ImportResult | null>(null);

  fixingMismatch = signal<Set<number>>(new Set());
  fixingClub = signal<Set<number>>(new Set());
  creatingPlayers = signal<Set<number>>(new Set());

  importableCount = computed(() => this.rows().filter((r) => r.importable).length);
  /** Matched rows that are not yet complete AND won't be fixed by the bulk "Weiter" button (club deviates from / can't be resolved against / doesn't belong to the CSV's division, or Marktwert unrealistisch). */
  clubMismatchCount = computed(
    () => this.matchedRows().filter((r) => r.club_mismatch || r.club_unresolved || r.division_mismatch || r.price_too_high).length
  );

  caseTab = signal<'matched' | 'unmatched' | 'missing'>('matched');

  searchQuery = signal('');
  hideComplete = signal(false);

  private matchesSearch(haystacks: (string | null | undefined)[]): boolean {
    const q = this.searchQuery().trim().toLowerCase();
    if (!q) return true;
    return haystacks.some((h) => (h ?? '').toLowerCase().includes(q));
  }

  isRowComplete(r: PlayerImportRow): boolean {
    return !r.club_mismatch && !r.division_mismatch && !r.price_too_high && r.already_in_season && !r.position_price_mismatch;
  }

  matchedRows = computed(() => this.rows().filter((r) => r.isMatched));
  unmatchedRows = computed(() => this.rows().filter((r) => !r.isMatched));
  creatableUnmatchedRows = computed(() =>
    this.unmatchedRows().filter((r) => r.csv_position && r.csv_price && !r.hasDuplicateCandidate && !r.division_mismatch && !r.price_too_high)
  );

  // Summary tiles above the tabs — all percentages relative to the full CSV row count.
  private percentOf(count: number): number {
    const total = this.rows().length;
    return total > 0 ? Math.round((count / total) * 100) : 0;
  }

  doneCount = computed(() => this.rows().filter((r) => this.isRowComplete(r)).length);
  donePercent = computed(() => this.percentOf(this.doneCount()));
  importablePercent = computed(() => this.percentOf(this.importableCount()));
  blockedPercent = computed(() => this.percentOf(this.clubMismatchCount()));
  newInCsvPercent = computed(() => this.percentOf(this.unmatchedRows().length));

  filteredMatchedRows = computed(() =>
    this.matchedRows().filter((r) => {
      if (this.hideComplete() && this.isRowComplete(r)) return false;
      return this.matchesSearch([
        r.csv_first_name,
        r.csv_last_name,
        r.csv_club_name,
        r.csv_position ? POSITION_LABEL[r.csv_position] : null,
      ]);
    })
  );

  filteredUnmatchedRows = computed(() =>
    this.unmatchedRows().filter((r) =>
      this.matchesSearch([
        r.csv_first_name,
        r.csv_last_name,
        r.csv_club_name,
        r.csv_position ? POSITION_LABEL[r.csv_position] : null,
      ])
    )
  );

  filteredMissingPlayers = computed(() =>
    this.missingPlayers().filter((m) => this.matchesSearch([m.displayname, m.club_name]))
  );

  sortCol = signal<'name' | 'club' | 'position' | 'price'>('name');
  sortDir = signal<'asc' | 'desc'>('asc');

  sortedRows = computed(() => {
    const col = this.sortCol();
    const dir = this.sortDir();
    const sign = dir === 'asc' ? 1 : -1;
    return [...this.filteredUnmatchedRows()].sort((a, b) => sign * this.compareRows(a, b, col));
  });

  private compareRows(a: PlayerImportRow, b: PlayerImportRow, col: 'name' | 'club' | 'position' | 'price'): number {
    switch (col) {
      case 'name':
        return (a.matched_displayname ?? a.csv_displayname).localeCompare(b.matched_displayname ?? b.csv_displayname);
      case 'club':
        return a.csv_club_name.localeCompare(b.csv_club_name);
      case 'position':
        return (a.csv_position ?? '').localeCompare(b.csv_position ?? '');
      case 'price':
        return (a.csv_price ?? 0) - (b.csv_price ?? 0);
    }
  }

  sort(col: 'name' | 'club' | 'position' | 'price'): void {
    if (this.sortCol() === col) {
      this.sortDir.update((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      this.sortCol.set(col);
      this.sortDir.set('asc');
    }
  }

  matchedSortCol = signal<'state' | 'firstName' | 'lastName' | 'club' | 'position' | 'price'>('state');
  matchedSortDir = signal<'asc' | 'desc'>('desc');

  sortedMatchedRows = computed(() => {
    const col = this.matchedSortCol();
    const dir = this.matchedSortDir();
    const sign = dir === 'asc' ? 1 : -1;
    return [...this.filteredMatchedRows()].sort((a, b) => sign * this.compareMatchedRows(a, b, col));
  });

  /** Number of "green" states (found, no club conflict, has entry, entry matches CSV) — used for the initial sort. */
  private rowGreenScore(r: PlayerImportRow): number {
    return (
      1 + // Spieler gefunden (immer wahr in dieser Tabelle)
      (r.club_mismatch || r.club_unresolved || r.division_mismatch || r.price_too_high ? 0 : 1) +
      (r.already_in_season ? 1 : 0) +
      (r.already_in_season && !r.position_price_mismatch ? 1 : 0)
    );
  }

  private compareMatchedRows(
    a: PlayerImportRow,
    b: PlayerImportRow,
    col: 'state' | 'firstName' | 'lastName' | 'club' | 'position' | 'price'
  ): number {
    switch (col) {
      case 'state':
        return this.rowGreenScore(a) - this.rowGreenScore(b);
      case 'firstName':
        return a.csv_first_name.localeCompare(b.csv_first_name);
      case 'lastName':
        return a.csv_last_name.localeCompare(b.csv_last_name);
      case 'club':
        return a.csv_club_name.localeCompare(b.csv_club_name);
      case 'position':
        return (a.csv_position ?? '').localeCompare(b.csv_position ?? '');
      case 'price':
        return (a.csv_price ?? 0) - (b.csv_price ?? 0);
    }
  }

  matchedSort(col: 'state' | 'firstName' | 'lastName' | 'club' | 'position' | 'price'): void {
    if (this.matchedSortCol() === col) {
      this.matchedSortDir.update((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      this.matchedSortCol.set(col);
      this.matchedSortDir.set('asc');
    }
  }

  missingSortCol = signal<'name' | 'club'>('name');
  missingSortDir = signal<'asc' | 'desc'>('asc');

  sortedMissingPlayers = computed(() => {
    const col = this.missingSortCol();
    const dir = this.missingSortDir();
    const sign = dir === 'asc' ? 1 : -1;
    return [...this.filteredMissingPlayers()].sort((a, b) => {
      const cmp = col === 'name' ? a.displayname.localeCompare(b.displayname) : a.club_name.localeCompare(b.club_name);
      return sign * cmp;
    });
  });

  sortMissing(col: 'name' | 'club'): void {
    if (this.missingSortCol() === col) {
      this.missingSortDir.update((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      this.missingSortCol.set(col);
      this.missingSortDir.set('asc');
    }
  }

  constructor() {
    this.cache.ensureDivisions();
    this.cache.ensureLeague();
  }

  onCsvFileSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    this.selectedFile.set(file);
    this.analyzeCsv(file, null, 'confirm-division');
    (event.target as HTMLInputElement).value = '';
  }

  /**
   * Runs the CSV analysis. With divisionId=null the backend auto-detects the division from
   * the plurality of resolved CSV clubs; an explicit divisionId (from the confirm-division
   * dropdown) re-analyses the same file against that division instead.
   */
  private analyzeCsv(file: File, divisionId: string | null, nextStepOnSuccess: 'confirm-division' | 'preview'): void {
    this.uploading.set(true);
    this.uploadError.set(null);
    this.api.previewPlayerSeasonImport(file, divisionId).subscribe({
      next: (res) => {
        this.uploading.set(false);
        this.rows.set((res.rows ?? []).map((r: any) => PlayerImportRow.from(r)));
        this.missingPlayers.set((res.missing_players ?? []).map((m: any) => MissingClubMember.from(m)));
        this.seasonId.set(res.season_id);
        this.seasonStartDate.set(res.season_start_date);
        this.divisionWarning.set(!!res.division_warning);
        this.divisionMismatchCount.set(res.division_mismatch_count ?? 0);
        this.resolvedClubCount.set(res.resolved_club_count ?? 0);
        this.divisionCandidates.set(res.division_candidates ?? []);
        this.divisionAutoDetected.set(!!res.division_auto_detected);

        // res.division_id === null means nothing could be attributed to any division at all —
        // fall back to the league's own division as a dropdown suggestion, but the admin still
        // has to explicitly confirm before any rows (and the division_mismatch block) exist.
        this.analyzedDivisionId.set(res.division_id ?? null);
        const resolvedDivisionId: string | null = res.division_id ?? this.cache.leagueDivisionId();
        this.divisionId.set(resolvedDivisionId);
        this.pendingDivisionId.set(resolvedDivisionId);
        this.step.set(res.division_id ? nextStepOnSuccess : 'confirm-division');
      },
      error: (err) => {
        this.uploading.set(false);
        this.uploadError.set(err?.error?.message ?? 'CSV konnte nicht verarbeitet werden');
      },
    });
  }

  confirmDivision(): void {
    const chosen = this.pendingDivisionId();
    const file = this.selectedFile();
    if (!chosen || !file) return;

    if (chosen === this.analyzedDivisionId()) {
      this.step.set('preview');
      return;
    }
    this.analyzeCsv(file, chosen, 'preview');
  }

  fixPositionPrice(row: PlayerImportRow): void {
    if (!row.existing_player_in_season_id) return;

    this.fixingMismatch.update((s) => new Set([...s, row.kicker_id]));
    this.api.patch(`player_in_season/${row.existing_player_in_season_id}`, {
      position: row.csv_position,
      price: row.csv_price,
    }).subscribe({
      next: () => {
        this.fixingMismatch.update((s) => { const n = new Set(s); n.delete(row.kicker_id); return n; });
        this.rows.update((list) => list.map((r) => r !== row ? r : PlayerImportRow.from({
          ...r,
          existing_position: r.csv_position,
          existing_price: r.csv_price,
          position_price_mismatch: false,
        })));
      },
      error: () => {
        this.fixingMismatch.update((s) => { const n = new Set(s); n.delete(row.kicker_id); return n; });
      },
    });
  }

  fixClubMismatch(row: PlayerImportRow): void {
    if (!row.current_player_in_club_id || !row.matched_player_id || !row.matched_club_id) return;

    this.fixingClub.update((s) => new Set([...s, row.kicker_id]));
    const today = todayIso();
    this.api.patch(`player_in_club/${row.current_player_in_club_id}`, { to_date: today }).subscribe({
      next: () => {
        this.api.post('player_in_club', {
          player_id: row.matched_player_id,
          club_id: row.matched_club_id,
          from_date: today,
        }).subscribe({
          next: () => {
            this.fixingClub.update((s) => { const n = new Set(s); n.delete(row.kicker_id); return n; });
            this.rows.update((list) => list.map((r) => r !== row ? r : PlayerImportRow.from({
              ...r,
              current_club_id: r.matched_club_id,
              current_club_name: r.csv_club_name,
              current_club_logo_uploaded: r.club_logo_uploaded,
              club_mismatch: false,
              club_confirmed: true,
              importable: !r.already_in_season && !!r.csv_position && !!r.csv_price && r.csv_price > 0 && !r.division_mismatch,
            })));
          },
          error: () => {
            this.fixingClub.update((s) => { const n = new Set(s); n.delete(row.kicker_id); return n; });
          },
        });
      },
      error: () => {
        this.fixingClub.update((s) => { const n = new Set(s); n.delete(row.kicker_id); return n; });
      },
    });
  }

  createMissingPlayer(row: PlayerImportRow): void {
    const seasonId = this.seasonId();
    if (!seasonId || !row.csv_position || !row.csv_price) return;

    this.creatingPlayers.update((s) => new Set([...s, row.kicker_id]));
    this.api.post<{ id: string }>('player/create', {
      kicker_id: row.kicker_id,
      first_name: row.csv_first_name,
      last_name: row.csv_last_name,
      displayname: row.csv_displayname,
      season_id: seasonId,
      position: row.csv_position,
      price: row.csv_price,
      club_id: row.matched_club_id ?? undefined,
      from_date: this.seasonStartDate() ?? undefined,
    }).subscribe({
      next: ({ id }) => {
        this.creatingPlayers.update((s) => { const n = new Set(s); n.delete(row.kicker_id); return n; });
        this.rows.update((list) => list.map((r) => r !== row ? r : PlayerImportRow.from({
          ...r,
          matched_player_id: id,
          matched_displayname: r.csv_displayname,
          current_club_id: r.matched_club_id,
          current_club_name: r.csv_club_name,
          current_club_logo_uploaded: r.club_logo_uploaded,
          club_mismatch: false,
          club_confirmed: !!r.matched_club_id,
          // POST /player/create legt player_in_season direkt mit an — Zeile hat also bereits
          // einen Eintrag und ist nicht (mehr) über "Spieler-Saison-Objekt erstellen" importierbar.
          already_in_season: true,
          existing_position: r.csv_position,
          existing_price: r.csv_price,
          position_price_mismatch: false,
          importable: false,
        })));
      },
      error: () => {
        this.creatingPlayers.update((s) => { const n = new Set(s); n.delete(row.kicker_id); return n; });
      },
    });
  }

  confirmCreateAll(): void {
    this.creatableUnmatchedRows()
      .filter((r) => !this.creatingPlayers().has(r.kicker_id))
      .forEach((r) => this.createMissingPlayer(r));
  }

  confirmImport(): void {
    const rows = this.rows()
      .filter((r) => r.importable)
      .map((r) => ({
        player_id: r.matched_player_id!,
        position: r.csv_position!,
        price: r.csv_price!,
      }));
    if (!rows.length) return;

    this.importing.set(true);
    this.api.importPlayerSeasonRows(rows).subscribe({
      next: (res) => {
        this.importing.set(false);
        this.importResult.set(res);
        this.step.set('result');
      },
      error: () => {
        this.importing.set(false);
      },
    });
  }

  reset(): void {
    this.step.set('upload');
    this.uploadError.set(null);
    this.selectedFile.set(null);
    this.divisionId.set(null);
    this.pendingDivisionId.set(null);
    this.analyzedDivisionId.set(null);
    this.divisionCandidates.set([]);
    this.divisionAutoDetected.set(false);
    this.rows.set([]);
    this.missingPlayers.set([]);
    this.seasonId.set(null);
    this.seasonStartDate.set(null);
    this.divisionWarning.set(false);
    this.divisionMismatchCount.set(0);
    this.resolvedClubCount.set(0);
    this.importResult.set(null);
    this.caseTab.set('matched');
    this.searchQuery.set('');
    this.hideComplete.set(false);
    this.sortCol.set('name');
    this.sortDir.set('asc');
    this.matchedSortCol.set('state');
    this.matchedSortDir.set('desc');
    this.missingSortCol.set('name');
    this.missingSortDir.set('asc');
  }
}
