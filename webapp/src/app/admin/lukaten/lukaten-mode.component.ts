import { Component, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { Season } from '../../core/models/season.model';

type LukatenMode = 'classic' | 'account';

/** Response von GET /season/active — Saison + Lukaten-Modus und ob er auf dieser Umgebung umschaltbar ist. */
interface ActiveSeason {
  id: string;
  start_date: string;
  lukaten_mode?: LukatenMode;
  lukaten_mode_switchable?: boolean;
}

/**
 * /verwaltung/lukaten: Schalter für den neuen Lukaten-Modus der aktiven Saison (Konto je Manager statt 100 Lukaten
 * je Liga und Saison, siehe docs/lukaten-economy-concept.md). Zum Ausprobieren auf der Development-Umgebung —
 * umschaltbar nur, wo die API es erlaubt (LUKATEN_MODE_SWITCH im .env).
 */
@Component({
  selector: 'app-lukaten-mode',
  standalone: false,
  templateUrl: './lukaten-mode.component.html',
  styleUrl: './lukaten-mode.component.scss',
})
export class LukatenModeComponent {
  private api = inject(ApiService);

  /** undefined = lädt, null = Fehler */
  season = signal<ActiveSeason | null | undefined>(undefined);
  saving = signal(false);
  error = signal<string | null>(null);

  seasonName = computed(() => {
    const s = this.season();
    return s ? Season.from(s).longDisplayName : '';
  });
  accountMode = computed(() => this.season()?.lukaten_mode === 'account');
  switchable = computed(() => this.season()?.lukaten_mode_switchable ?? false);

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.get<ActiveSeason>('season/active').subscribe({
      next: s => this.season.set(s),
      error: () => this.season.set(null),
    });
  }

  setMode(input: HTMLInputElement): void {
    const s = this.season();
    if (!s || this.saving()) return;
    const mode: LukatenMode = input.checked ? 'account' : 'classic';
    this.saving.set(true);
    this.error.set(null);
    this.api.patch(`season/${s.id}`, { lukaten_mode: mode }).subscribe({
      next: () => {
        this.saving.set(false);
        this.season.set({ ...s, lukaten_mode: mode });
      },
      error: err => {
        this.saving.set(false);
        this.error.set(err?.error?.message ?? 'Umschalten fehlgeschlagen');
        input.checked = this.accountMode(); // Schalter zurück auf den echten Stand
      },
    });
  }
}
