import { Component, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { LukatenPreviewService } from '../../core/lukaten-preview.service';

/** Response von GET /lukaten. */
interface LukatenState {
  mode: 'classic' | 'account';
  preview_available: boolean;   // diese Umgebung erlaubt die Vorschau (.env der API)
  preview: boolean;             // für diesen Request aktiv
  ready: boolean;               // Kontobuch vorhanden (Migration)
  balance: number | null;       // Kontostand im Konto-Modus, sonst null
  season_bonus: number;
}

/**
 * /verwaltung/lukaten: Vorschau des neuen Lukaten-Modus (Konto je Manager statt 100 Lukaten je Liga und Saison,
 * siehe docs/lukaten-economy-concept.md). Der Schalter gilt nur für diesen Admin auf diesem Gerät
 * (LukatenPreviewService) und nur, wo die API es erlaubt — die Saison selbst wird nicht umgestellt, weil sich
 * beide Umgebungen die Datenbank teilen.
 */
@Component({
  selector: 'app-lukaten-mode',
  standalone: false,
  templateUrl: './lukaten-mode.component.html',
  styleUrl: './lukaten-mode.component.scss',
})
export class LukatenModeComponent {
  private api = inject(ApiService);
  private preview = inject(LukatenPreviewService);

  /** undefined = lädt, null = Fehler */
  state = signal<LukatenState | null | undefined>(undefined);
  busy = signal(false);
  error = signal<string | null>(null);

  readonly previewOn = this.preview.enabled;
  available = computed(() => this.state()?.preview_available ?? false);
  ready = computed(() => this.state()?.ready ?? false);
  balance = computed(() => this.state()?.balance ?? null);
  seasonBonus = computed(() => this.state()?.season_bonus ?? 20);

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.get<LukatenState>('lukaten').subscribe({
      next: s => {
        // Schalter stand noch an, die Umgebung erlaubt die Vorschau aber nicht (mehr) → aus
        if (!s.preview_available && this.preview.enabled()) this.preview.set(false);
        this.state.set(s);
      },
      error: () => this.state.set(null),
    });
  }

  setPreview(on: boolean): void {
    this.error.set(null);
    this.preview.set(on);
    this.load();
  }

  /** Vorschau-Konto leeren — beim nächsten Abruf gibt es wieder den Startbonus */
  reset(): void {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set(null);
    this.api.delete('lukaten/preview').subscribe({
      next: () => { this.busy.set(false); this.load(); },
      error: err => {
        this.busy.set(false);
        this.error.set(err?.error?.message ?? 'Zurücksetzen fehlgeschlagen');
      },
    });
  }

  formatLukaten(v: number | null): string {
    if (v == null) return '–';
    return Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ',');
  }
}
