import { Component, computed, effect, signal } from '@angular/core';
import { inject } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../core/api.service';
import { AuthService } from '../auth/auth.service';

// Anonymes Aufruf-Tracking für Gäste ohne Konto, siehe POST /noten/track und die
// Datenschutzerklärung (Ziffer 10). Zwei localStorage-Schlüssel, unabhängig von der
// Consent-Entscheidung selbst als "unbedingt notwendig" ohne Einwilligung gespeichert (sonst
// müsste bei jedem Aufruf erneut gefragt werden):
//  - CONSENT_KEY: 'accepted' | 'declined' — die getroffene Entscheidung, oder fehlt = noch nie gefragt.
//  - ANON_ID_KEY: zufällige ID, NUR bei 'accepted' gesetzt, macht wiederkehrende Aufrufe desselben
//    Browsers erkennbar (server-seitig in noten_guest_visit).
// Bei 'declined' oder fehlender Entscheidung wird trotzdem ein Aufruf ohne ID getrackt (siehe
// trackVisit()) — ein einzelner, mit keinem anderen Aufruf verknüpfbarer Zähler, der ohne
// Einwilligung nach § 25 TDDDG zulässig ist (kein Wiedererkennen möglich).
const CONSENT_KEY = 'noten_guest_consent';
const ANON_ID_KEY = 'noten_guest_anon_id';

interface NotenPlayer {
  id: string;
  displayname: string;
  position: string | null;
  grade: number | null;
  points: number;
  participation: 'starting' | 'substitute';
  own: boolean;
  sds: boolean;
  goals: number;
  assists: number;
  clean_sheet: boolean;
  red_card: boolean;
  yellow_red_card: boolean;
}

interface NotenClub {
  id: string;
  name: string;
  short_name: string | null;
  logo_uploaded: boolean;
  players: NotenPlayer[];
}

interface NotenMatchday {
  id: string;
  number: number;
  start_date: string;
  kickoff_date: string;
}

interface NotenResponse {
  season_id: string | null;
  matchdays: NotenMatchday[];
  matchday: NotenMatchday | null;
  clubs: NotenClub[];
}

const EMPTY: NotenResponse = { season_id: null, matchdays: [], matchday: null, clubs: [] };

@Component({
  selector: 'app-noten',
  standalone: false,
  templateUrl: './noten.component.html',
  styleUrl: './noten.component.scss',
})
export class NotenComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);

  isLoggedIn = computed(() => this.auth.isLoggedIn());

  // Default false — eigene Spieler werden nur hervorgehoben, wenn der Manager es explizit anstößt.
  showOwn = signal(false);

  selectedMatchdayId = signal<string | null>(null);

  private state = toSignal(
    toObservable(this.selectedMatchdayId).pipe(
      switchMap((id) => {
        const url = id ? `noten?matchday_id=${id}` : 'noten';
        return this.api.get<NotenResponse>(url).pipe(
          map((data) => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as NotenResponse | null, loading: true, error: null as string | null }),
          catchError(() => of({ data: null as NotenResponse | null, loading: false, error: 'Fehler beim Laden' })),
        );
      }),
    ),
    { initialValue: { data: null as NotenResponse | null, loading: true, error: null as string | null } },
  );

  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);
  private data = computed(() => this.state().data ?? EMPTY);

  matchdays = computed(() => this.data().matchdays);
  matchday  = computed(() => this.data().matchday);
  clubs     = computed(() => this.data().clubs);

  // Consent-Banner nur für echte Gäste (kein Konto/kein eingeloggter Manager) — angemeldete
  // Manager werden bereits über manager_session erfasst, sobald sie authentifizierte Endpunkte
  // aufrufen; /noten selbst ist ein Guest-Endpunkt und löst dort keinen Heartbeat aus.
  showConsentBanner = signal(false);

  constructor() {
    // Erster Aufruf (ohne matchday_id) liefert den serverseitig gewählten Default-Spieltag —
    // selectedMatchdayId einmalig darauf synchronisieren, damit der Picker den richtigen Button
    // hervorhebt (löst einen erneuten, jetzt explizit matchday_id-tragenden Request aus, gleiche
    // Response — analog zum Auto-Season-Select auf der Spielerdetailseite).
    effect(() => {
      const md = this.matchday();
      if (md && this.selectedMatchdayId() === null) {
        this.selectedMatchdayId.set(md.id);
      }
    });

    if (!this.isLoggedIn()) this.trackVisit();
  }

  /**
   * Ein Tracking-Aufruf pro Seitenladung: mit anon_id bei erteiltem Consent, sonst ohne (siehe
   * Kommentar zu CONSENT_KEY/ANON_ID_KEY oben). Fire-and-forget — ein Fehler hier darf die Seite
   * nie beeinträchtigen, daher kein sichtbares Error-Handling.
   */
  private trackVisit(): void {
    let decision: string | null = null;
    let anonId: string | null = null;
    try {
      decision = localStorage.getItem(CONSENT_KEY);
      if (decision === 'accepted') {
        anonId = localStorage.getItem(ANON_ID_KEY);
        if (!anonId) {
          anonId = crypto.randomUUID();
          localStorage.setItem(ANON_ID_KEY, anonId);
        }
      }
    } catch {
      // localStorage nicht verfügbar (z.B. privater Modus) — ohne ID weiter, wie "kein Consent".
    }

    this.api.post<{ status: boolean }>('noten/track', anonId ? { anon_id: anonId } : {}).subscribe({
      error: () => {},
    });

    if (decision === null) this.showConsentBanner.set(true);
  }

  acceptTracking(): void {
    try {
      localStorage.setItem(CONSENT_KEY, 'accepted');
      localStorage.setItem(ANON_ID_KEY, crypto.randomUUID());
    } catch { /* siehe trackVisit() */ }
    this.showConsentBanner.set(false);
  }

  declineTracking(): void {
    try {
      localStorage.setItem(CONSENT_KEY, 'declined');
    } catch { /* siehe trackVisit() */ }
    this.showConsentBanner.set(false);
  }

  selectMatchday(id: string): void {
    this.selectedMatchdayId.set(id);
  }

  onMobileSelect(value: string): void {
    if (value) this.selectedMatchdayId.set(value);
  }

  private readonly posOrder: Record<string, number> = { GOALKEEPER: 0, DEFENDER: 1, MIDFIELDER: 2, FORWARD: 3 };
  private readonly posLabel: Record<string, string> = { GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU' };

  positionLabel(pos: string | null): string {
    return pos ? (this.posLabel[pos] ?? pos) : '—';
  }

  positionColor(pos: string | null): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'var(--position-goalkeeper)',
      DEFENDER:   'var(--position-defender)',
      MIDFIELDER: 'var(--position-midfielder)',
      FORWARD:    'var(--position-forward)',
    };
    return pos ? (map[pos] ?? 'transparent') : 'transparent';
  }

  gradeVar(grade: number | null): string {
    if (!grade) return 'var(--grade-unset)';
    const key = Math.round(grade * 2) * 5; // 1.0→10, 1.5→15, …, 6.0→60
    return `var(--grade-${key})`;
  }

  logoUrl(club: NotenClub): string {
    if (!club.logo_uploaded) return 'img/placeholders/club.png';
    return `https://img.die-bestesten.de/club/${club.id}.png`;
  }

  avgGrade(club: NotenClub): string | null {
    const graded = club.players.filter((p) => p.grade !== null);
    if (!graded.length) return null;
    return (graded.reduce((s, p) => s + p.grade!, 0) / graded.length).toFixed(2);
  }

  range(n: number): number[] {
    return Array.from({ length: n }, (_, i) => i);
  }

  totalPoints(club: NotenClub): number {
    return club.players.reduce((s, p) => s + p.points, 0);
  }
}
