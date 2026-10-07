import { Injectable, inject, signal } from '@angular/core';
import { ApiService } from './api.service';
import { AuthService } from '../auth/auth.service';

/**
 * Lukaten-Kontostand des eingeloggten Managers (GET /lukaten) — für die Anzeige in der Topbar. Ein Konto je
 * Manager, unabhängig von Liga und Saison. Beim App-Start geladen und erneut, sobald der Tab wieder angesehen wird
 * (Gutschriften kommen auch von außen: Spieltagsabschluss, bestätigter Kauf). Wer Lukaten ausgibt oder bekommt
 * (Pack-Kauf, Tipp, Lukaten-Kauf), meldet den neuen Stand per set() oder lädt per refresh() neu.
 */
@Injectable({ providedIn: 'root' })
export class LukatenService {
  private api = inject(ApiService);
  private auth = inject(AuthService);

  /** undefined = noch nicht geladen, null = kein Konto (API ohne Kontobuch oder Fehler) */
  readonly balance = signal<number | null | undefined>(undefined);

  private started = false;

  /** Einmalig beim App-Start (Topbar). */
  start(): void {
    if (this.started) return;
    this.started = true;
    this.refresh();
    document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refresh(); });
  }

  refresh(): void {
    if (!this.auth.getToken()) return;
    this.api.get<{ ready: boolean; balance: number | null }>('lukaten').subscribe({
      next: s => this.balance.set(s.ready ? s.balance : null),
      error: () => this.balance.set(null),
    });
  }

  /** Neuer Stand aus der Antwort eines Kaufs oder Tipps — spart den erneuten Abruf. */
  set(balance: number | null | undefined): void {
    if (balance != null) this.balance.set(balance);
  }

  /** z.B. "1.250" bzw. "12,5" (Tippgewinne sind nicht immer ganzzahlig) */
  static format(v: number | null | undefined): string {
    if (v == null) return '–';
    return v.toLocaleString('de-DE', { maximumFractionDigits: 2 });
  }
}
