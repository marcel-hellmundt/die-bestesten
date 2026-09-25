import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { ApiService } from './api.service';
import { AuthService } from '../auth/auth.service';

export type StickerPackSource = 'daily' | 'milestone' | 'matchday_best' | 'admin';

export interface StickerPack {
  id: string;
  source: StickerPackSource;
  size: number;
  created_at: string;
  league_name: string | null;
  milestone_points: number | null;  // Meilenstein-Pack: erreichte Punkte-Schwelle
  matchday_number: number | null;   // Spieltagsbester-Pack: Spieltag
}

/** Je gezogenem Sticker: key = player_id bzw. '{club_id}-logo' / '{club_id}-stadium'. */
export interface StickerCollectionEntry {
  key: string;
  count: number;
  silver: number;
  gold: number;
  first_at: string;
}

/** Response von GET /sticker/me. */
export interface StickerState {
  enabled: boolean;
  album_ready: boolean;
  season_id: string | null;
  packs: StickerPack[];
  collection: StickerCollectionEntry[];
}

export interface OpenedPack {
  pack: { id: string; source: StickerPackSource; size: number };
  cards: { key: string; holo: 'silver' | 'gold' | null; is_new: boolean }[];
}

export const PACK_SOURCE_LABEL: Record<StickerPackSource, string> = {
  daily: 'Tages-Pack',
  milestone: 'Meilenstein-Pack',
  matchday_best: 'Spieltagsbester-Pack',
  admin: 'Bonus-Pack',
};

/** Anlass eines Packs in Worten, z.B. "200 Punkte erreicht · Liga" oder "Spieltag 5 · Liga". */
export function packDetail(p: Pick<StickerPack, 'source' | 'milestone_points' | 'matchday_number' | 'league_name'>): string {
  const parts: string[] = [];
  if (p.source === 'daily') parts.push('fürs Vorbeischauen');
  if (p.source === 'milestone' && p.milestone_points) parts.push(`${p.milestone_points} Saisonpunkte erreicht`);
  if (p.source === 'matchday_best') parts.push(p.matchday_number ? `bestes Team an Spieltag ${p.matchday_number}` : 'bestes Team des Spieltags');
  if (p.source === 'admin') parts.push('vom Admin vergeben');
  if (p.league_name) parts.push(p.league_name);
  return parts.join(' · ');
}

/**
 * "Die Klebrigsten" — eigener Album-Status (GET /sticker/me). Beim App-Start abgefragt (Topbar) und
 * erneut, sobald ein neuer Tag beginnt: der Abruf vergibt serverseitig das tägliche Pack ("App öffnen").
 */
@Injectable({ providedIn: 'root' })
export class StickerStatusService {
  private api = inject(ApiService);
  private auth = inject(AuthService);

  readonly state = signal<StickerState | null>(null);
  readonly enabled = computed(() => this.state()?.enabled ?? false);
  readonly packs = computed(() => this.state()?.packs ?? []);
  readonly unopenedCount = computed(() => this.packs().length);

  private started = false;
  private lastDay = '';

  /** Einmalig beim App-Start; prüft danach minütlich auf Tageswechsel (tägliches Pack). */
  start(): void {
    if (this.started) return;
    this.started = true;
    this.refresh();
    setInterval(() => { if (this.today() !== this.lastDay) this.refresh(); }, 60_000);
  }

  refresh(): void {
    if (!this.auth.getToken()) return;
    this.lastDay = this.today();
    this.api.get<StickerState>('sticker/me').subscribe({
      next: s => this.state.set(s),
      error: () => {}, // z.B. API ohne Sticker-Migration — Feature bleibt dann einfach aus
    });
  }

  openPack(packId: string): Observable<OpenedPack> {
    return this.api.post<OpenedPack>(`sticker/pack/${packId}/open`).pipe(tap(() => this.refresh()));
  }

  private today(): string {
    return new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD, lokale Zeit
  }
}
