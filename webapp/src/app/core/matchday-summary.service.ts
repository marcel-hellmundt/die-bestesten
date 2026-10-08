import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { ApiService } from './api.service';
import { AuthService } from '../auth/auth.service';
import { LukatenService } from './lukaten.service';
import { NotificationService } from './notification.service';
import { StickerStatusService } from './sticker-status.service';

export interface SummaryTeam {
  id: string;
  team_name: string;
  color: string | null;
  season_id: string;
}

/** Aufgestellter Spieler mit Tor, Vorlage oder SdS */
export interface SummaryHighlight {
  player_id: string;
  displayname: string;
  position: string | null;
  photo_season_id: string | null;   // Saison des Spielerfotos, null = kein Foto
  goals: number;
  assists: number;
  sds: boolean;
  points: number;
}

export interface SummaryH2H {
  match_id: string;
  phase: string;
  home: boolean;
  opponent: { team_id: string; team_name: string; color: string | null; season_id: string };
  goals_for: number;
  goals_against: number;
  outcome: 'win' | 'draw' | 'loss';
}

/** Das Spiel: was das Team an dem Spieltag geholt hat */
export interface SummaryResult {
  valid: boolean;                    // false = ungültige Aufstellung, nicht gewertet
  points: number;
  max_points: number;
  rank: number | null;               // Platz am Spieltag unter den gewerteten Teams
  teams: number;
  table_rank: number;                // Tabellenplatz nach diesem Spieltag
  table_rank_before: number | null;  // … nach dem vorherigen (null am ersten Spieltag)
  table_points: number;
  table_teams: number;
  stats: { goals: number; assists: number; clean_sheets: number; sds: number; red_cards: number; yellow_red_cards: number };
  highlights: SummaryHighlight[];
  income: number;                    // Spieltagseinnahmen fürs Budget (Euro)
  fine: number;                      // Strafe in Euro, 0 = keine
  h2h: SummaryH2H | null;
}

export interface SummaryPack {
  source: 'matchday_best' | 'milestone';
  milestone_points: number | null;
  size: number;
}

export interface SummaryAchievement {
  name: string;
  icon: string | null;
  level: 'bronze' | 'silver' | 'gold';
  reason: string | null;
}

/** Was nicht zum Spiel gehört */
export interface SummaryExtras {
  lukaten_entries: { amount: number; by_type: { participation: number; note: number; stats: number } } | null;
  bets: { tips: number; correct: number; stakes: number; payouts: number } | null;
  packs: SummaryPack[];
  achievements: SummaryAchievement[];
}

/** Spieltags-Zusammenfassung eines Managers in einer Liga (GET /matchday_summary). */
export interface MatchdaySummary {
  id: string | null;                 // null = Vorschau
  created_at: string | null;
  seen_at: string | null;
  matchday: { id: string; number: number; season_id: string };
  league: { id: string | null; name: string | null };
  team: SummaryTeam | null;          // null = kein Team in dieser Liga (nur Extras)
  result: SummaryResult | null;
  extras: SummaryExtras;
}

/** Nach einer Abfrage so lange keine weitere (der erste Stand der Benachrichtigungen kommt direkt nach dem Start) */
const CHECK_PAUSE_MS = 3000;

/**
 * Spieltags-Zusammenfassung: nach dem Abschluss eines Spieltags erscheint einmal groß, was man geholt hat (Overlay
 * app-matchday-summary in der Shell). Abgefragt beim App-Start, wenn der Tab wieder angesehen wird und sobald eine
 * neue Benachrichtigung eintrifft; eingeblendet wird nur im sichtbaren Tab und nicht über einem offenen Pack-Dialog.
 * Beim Einblenden gilt die Zusammenfassung als gesehen (geräteübergreifend). Abschaltbar unter Einstellungen →
 * Benachrichtigungen → Einblendungen (overlay_matchday); über die Benachrichtigung zum Spieltag lässt sie sich
 * jederzeit wieder öffnen (open()).
 */
@Injectable({ providedIn: 'root' })
export class MatchdaySummaryService {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private notif = inject(NotificationService);
  private lukaten = inject(LukatenService);
  private stickers = inject(StickerStatusService);

  /** gerade eingeblendet (mehrere Ligen oder Spieltage: nacheinander) */
  readonly shown = signal<MatchdaySummary[]>([]);
  /** Vorschau aus der Verwaltung — schreibt nichts */
  readonly preview = signal(false);
  /** abgeholt, aber noch nicht eingeblendet */
  private waiting = signal<MatchdaySummary[]>([]);
  private checked = signal(false);
  /**
   * Andere Einblendungen (Achievements, Packs) warten: bis die erste Abfrage beantwortet ist und solange eine
   * Zusammenfassung ansteht oder offen ist — sie kommt zuerst, die Packs daraus danach zum Aufreißen.
   */
  readonly blocking = computed(() => !this.checked() || this.waiting().length > 0 || this.shown().length > 0);

  private started = false;
  private lastCheck = 0;
  private lastUnread: number | null = null;

  constructor() {
    // Anstehende einblenden, sobald nichts dagegen spricht
    effect(() => {
      const waiting = this.waiting();
      const free = this.shown().length === 0 && this.notif.preferencesLoaded()
        && this.stickers.tabVisible() && !this.stickers.announcing();
      if (!waiting.length || !free) return;
      untracked(() => {
        this.waiting.set([]);
        if (!this.notif.isEnabled('overlay_matchday')) return; // abgeschaltet: bleibt über die Benachrichtigung erreichbar
        this.preview.set(false);
        this.shown.set(waiting);
        const ids = waiting.map(s => s.id).filter((id): id is string => !!id);
        this.api.patch('matchday_summary/seen', { ids }).subscribe({ error: () => {} });
        this.lukaten.refresh(); // Lukaten für Einträge und Tippgewinne sind mit dem Abschluss gebucht
      });
    });

    // Neue Benachrichtigung (z.B. "Spieltag N abgeschlossen") → nachsehen, ob eine Zusammenfassung dazugekommen ist
    effect(() => {
      const unread = this.notif.unreadCount();
      const grown = this.lastUnread !== null && unread > this.lastUnread;
      this.lastUnread = unread;
      if (grown && this.started) untracked(() => this.check());
    });
  }

  /** Beim App-Start (Shell) — nach einem erneuten Anmelden von vorn, nichts vom vorherigen Manager stehen lassen. */
  start(): void {
    this.shown.set([]);
    this.waiting.set([]);
    this.preview.set(false);
    this.checked.set(false);
    this.lastCheck = 0;
    this.check();
    if (this.started) return;
    this.started = true;
    document.addEventListener('visibilitychange', () => { if (!document.hidden) this.check(); });
  }

  /** Noch nicht gesehene Zusammenfassungen der letzten Tage abholen. */
  check(): void {
    if (!this.auth.getToken()) { this.checked.set(true); return; }
    if (Date.now() - this.lastCheck < CHECK_PAUSE_MS) return;
    this.lastCheck = Date.now();
    this.api.get<{ ready: boolean; summaries: MatchdaySummary[] }>('matchday_summary').subscribe({
      next: r => {
        const open = new Set(this.shown().map(s => s.id));
        this.waiting.set((r.summaries ?? []).filter(s => !open.has(s.id)));
        this.checked.set(true);
      },
      error: () => this.checked.set(true), // z.B. API ohne das Feature — dann wird einfach nichts eingeblendet
    });
  }

  /** Eine Zusammenfassung wieder öffnen (aus der Benachrichtigung). */
  open(id: string): Observable<MatchdaySummary> {
    return this.api.get<MatchdaySummary>(`matchday_summary/${id}`).pipe(tap(s => this.show([s], false)));
  }

  /** Direkt einblenden — die Vorschau der Verwaltung übergibt hier ihre (nirgends gespeicherte) Zusammenfassung. */
  show(summaries: MatchdaySummary[], preview: boolean): void {
    this.preview.set(preview);
    this.shown.set(summaries);
  }

  close(): void {
    this.shown.set([]);
    this.preview.set(false);
  }
}
