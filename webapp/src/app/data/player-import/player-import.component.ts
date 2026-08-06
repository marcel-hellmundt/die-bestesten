import { Component, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { PlayerImportRow } from '../../core/models/player-import-row.model';
import { POSITION_COLOR, POSITION_LABEL } from '../../core/constants';

interface ImportResult {
  created_count: number;
  skipped: { player_id: string; reason: string }[];
}

@Component({
  selector: 'app-data-player-import',
  standalone: false,
  templateUrl: './player-import.component.html',
  styleUrl: './player-import.component.scss',
})
export class PlayerImportDataComponent {
  private api = inject(ApiService);

  readonly POSITION_LABEL = POSITION_LABEL;
  readonly POSITION_COLOR = POSITION_COLOR;

  step = signal<'upload' | 'preview' | 'result'>('upload');

  uploading = signal(false);
  uploadError = signal<string | null>(null);

  rows = signal<PlayerImportRow[]>([]);
  seasonId = signal<string | null>(null);

  importing = signal(false);
  importResult = signal<ImportResult | null>(null);

  importableCount = computed(() => this.rows().filter((r) => r.importable).length);
  matchedCount = computed(() => this.rows().filter((r) => r.isMatched).length);
  duplicateCount = computed(() => this.rows().filter((r) => r.isMatched && r.already_in_season).length);
  unmatchedCount = computed(() => this.rows().filter((r) => !r.isMatched).length);

  onCsvFileSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    this.uploading.set(true);
    this.uploadError.set(null);
    this.api.previewPlayerSeasonImport(file).subscribe({
      next: (res) => {
        this.uploading.set(false);
        this.rows.set((res.rows ?? []).map((r: any) => PlayerImportRow.from(r)));
        this.seasonId.set(res.season_id);
        this.step.set('preview');
      },
      error: (err) => {
        this.uploading.set(false);
        this.uploadError.set(err?.error?.message ?? 'CSV konnte nicht verarbeitet werden');
      },
    });
    (event.target as HTMLInputElement).value = '';
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
    this.rows.set([]);
    this.seasonId.set(null);
    this.importResult.set(null);
  }
}
