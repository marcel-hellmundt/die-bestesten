import { Injectable, InjectionToken, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { StickerCollectionEntry } from '../../core/sticker-status.service';
import { HoloVariant, Timeline, simulateSeason, stickerWeights } from '../sticker-sim';
import { StickerCardData, StickerHolo } from '../sticker-card/sticker-card.component';
import {
  AlbumClub, AlbumPreview, DEFAULT_PROFILES, DEFAULT_SHARED_PARAMS,
  Sticker, Tier, clubStickerPrice, paramsFor, tierOf,
} from './album.model';

const DAY_MS = 86_400_000;
const FALLBACK_DAYS = 255;
const IMG = 'https://img.die-bestesten.de';

/** Sammlung eines Managers: je Sticker Anzahl, beste Holo-Variante und Zeitpunkt des ersten Zugs. */
export interface Collection {
  counts: Uint16Array;
  holo: (StickerHolo | null)[];
  firstAt: Float64Array;  // sortierbarer Zeitpunkt des ersten Zugs (Demo: Saisontag, echt: ms), -1 = noch nicht gezogen
  holoSilver: number;
  holoGold: number;
}

/** Datenquelle des Albums — je Seite per Provider gesetzt: eingefrorenes Album bzw. Live-Vorschau (Simulation). */
export const ALBUM_SOURCE = new InjectionToken<'sticker/album' | 'sticker/album_preview'>('ALBUM_SOURCE');

/**
 * Album der aktiven Saison als gemeinsame Grundlage für Sammelalbum (GET /sticker/album, eingefroren)
 * und Simulation (GET /sticker/album_preview, live): flache Sticker-Liste inkl. 2 Vereins-Sticker
 * (Wappen, Stadion) je Club, Zeitachse, Bild-URLs, Kartendaten, Sammlungen (echt + Demo).
 * Nicht root-weit: jede Seite stellt die Instanz selbst bereit ({ provide: ALBUM_SOURCE, … }, StickerAlbumService).
 */
@Injectable()
export class StickerAlbumService {
  private api = inject(ApiService);
  private source = inject(ALBUM_SOURCE);
  private reloadTick = signal(0);

  private state = toSignal(
    toObservable(this.reloadTick).pipe(
      switchMap(() => this.api.get<AlbumPreview>(this.source).pipe(
        map(data => ({ data, loading: false, error: null as string | null })),
        startWith({ data: null as AlbumPreview | null, loading: true, error: null as string | null }),
        catchError(() => of({ data: null as AlbumPreview | null, loading: false, error: 'Album konnte nicht geladen werden' })),
      )),
    ),
    { initialValue: { data: null as AlbumPreview | null, loading: true, error: null as string | null } },
  );

  /** Album neu laden (z.B. nach dem Einfrieren per POST /sticker/album/sync). */
  reload(): void {
    this.reloadTick.update(n => n + 1);
  }
  loading  = computed(() => this.state().loading);
  error    = computed(() => this.state().error);
  seasonId = computed(() => this.state().data?.season_id ?? null);
  clubs    = computed(() => this.state().data?.clubs ?? []);

  /** Flache Sticker-Liste: je Club zuerst Wappen, dann Stadion, dann Spieler (API-Reihenfolge: Position, Marktwert). */
  stickers = computed<Sticker[]>(() => {
    const out: Sticker[] = [];
    const clubs = this.clubs();
    clubs.forEach((c, clubIdx) => {
      // Seltenheit der Vereins-Sticker nach Vorsaison-Platz (Clubs kommen bereits in dieser Reihenfolge);
      // beim eingefrorenen Album liefert die API den gespeicherten Wert
      const price = c.sticker_price ?? clubStickerPrice(clubIdx, clubs.length);
      const club = (kind: 'logo' | 'stadium', name: string): Sticker => ({
        id: `${c.id}-${kind}`, displayname: name, first_name: null, last_name: null,
        position: null, price, photo_uploaded: false,
        idx: out.length, clubIdx, kind, tier: tierOf(price),
      });
      out.push(club('logo', c.name));
      out.push(club('stadium', c.stadium_name ?? c.name));
      c.players.forEach(p => out.push({ ...p, idx: out.length, clubIdx, kind: 'player', tier: tierOf(p.price) }));
    });
    return out;
  });

  /** Je Verein seine Sticker (Seiten des Albums / Zeilen der Simulation). */
  rows = computed(() => {
    const byClub = this.clubs().map(c => ({ club: c, stickers: [] as Sticker[] }));
    for (const s of this.stickers()) byClub[s.clubIdx].stickers.push(s);
    return byClub;
  });

  tierCounts = computed(() => {
    const counts: Record<Tier, number> = { common: 0, rare: 0, epic: 0, legendary: 0 };
    for (const s of this.stickers()) counts[s.tier]++;
    return counts;
  });

  // ── Zeitachse: Stichtag bis Auswertung des letzten Spieltags ──────────────
  cutoff = computed(() => {
    const c = this.state().data?.cutoff_date;
    return c ? new Date(c + 'T00:00:00') : null;
  });

  timeline = computed<Timeline>(() => {
    const cutoff = this.cutoff();
    const mds = this.state().data?.matchdays ?? [];
    if (!cutoff || mds.length === 0) {
      // Fallback: 31 Spieltage gleichmäßig verteilt
      const days = FALLBACK_DAYS;
      return { days, matchdayDays: Array.from({ length: 31 }, (_, i) => Math.round((i + 1) * days / 31)) };
    }
    // Spieltag gilt 2 Tage nach Anpfiff als abgeschlossen (Meilenstein-/Spieltagsbester-Packs)
    const matchdayDays = mds.map(m => Math.floor((new Date(m.kickoff_date.replace(' ', 'T')).getTime() + 2 * DAY_MS - cutoff.getTime()) / DAY_MS));
    return { days: Math.max(...matchdayDays, 1), matchdayDays };
  });

  dateOf(day: number): Date | null {
    const c = this.cutoff();
    return c ? new Date(c.getTime() + day * DAY_MS) : null;
  }

  /** Heutiger Saisontag (seit Stichtag), auf die Saison begrenzt; vor dem Stichtag -1 (noch nichts gesammelt). */
  todayDay = computed(() => {
    const c = this.cutoff();
    if (!c) return 0;
    const d = Math.floor((Date.now() - c.getTime()) / DAY_MS);
    return Math.min(d, this.timeline().days);
  });

  // ── Bilder ────────────────────────────────────────────────────────────────
  playerPhotoUrl(s: Sticker): string | null {
    const seasonId = this.seasonId();
    return s.kind === 'player' && s.photo_uploaded && seasonId ? `${IMG}/player/${seasonId}/${s.id}.png` : null;
  }

  clubLogoUrl(c: AlbumClub): string {
    return c.logo_uploaded ? `${IMG}/club/${c.id}.png` : 'img/placeholders/club.png';
  }

  /** Stadion-Foto des Vereins (Asset-Server club/stadium/{id}.jpg) — Karten-Hintergrund bzw. Stadion-Sticker. */
  clubStadiumUrl(clubId: string): string {
    return `${IMG}/club/stadium/${clubId}.jpg`;
  }

  /** Vorschaubild eines Stickers (Tooltip u.ä.): Spielerfoto, Wappen bzw. Stadion. */
  stickerImageUrl(s: Sticker): string | null {
    const club = this.clubs()[s.clubIdx];
    if (s.kind === 'logo') return this.clubLogoUrl(club);
    if (s.kind === 'stadium') return this.clubStadiumUrl(club.id);
    return this.playerPhotoUrl(s);
  }

  /** Daten für app-sticker-card aus einem Album-Sticker. */
  cardData(s: Sticker, holo: StickerHolo | null): StickerCardData {
    const club = this.clubs()[s.clubIdx];
    const base = {
      clubLogoUrl: club.logo_uploaded ? this.clubLogoUrl(club) : null,
      clubName: club.name,
      clubPrimaryColor: club.primary_color,
      clubSecondaryColor: club.secondary_color,
      tier: s.tier,
      holo,
    };
    if (s.kind === 'logo') {
      return { ...base, kind: 'logo', displayname: club.name, firstName: 'Wappen', photoUrl: null };
    }
    if (s.kind === 'stadium') {
      return {
        ...base, kind: 'stadium', displayname: club.stadium_name ?? club.name, firstName: 'Stadion',
        photoUrl: null, stadiumUrl: this.clubStadiumUrl(club.id),
      };
    }
    return {
      ...base, kind: 'player', displayname: s.displayname, firstName: s.first_name,
      photoUrl: this.playerPhotoUrl(s),
      backgroundUrls: [this.clubStadiumUrl(club.id)], // Holo-Karten ignorieren das Hintergrundbild selbst
    };
  }

  // ── Demo-Sammlung (bis es echte Packs gibt) ───────────────────────────────
  /**
   * Simulierte Saison mit den Standard-Regeln und dem Profil "Durchschnitt", Seed = Manager + Saison
   * (stabil je Manager), ausgewertet bis `day` (inkl.).
   */
  demoCollection(managerId: string, day: number): Collection {
    const stickers = this.stickers();
    const n = stickers.length;
    const counts = new Uint16Array(n);
    const firstDay = new Float64Array(n).fill(-1);
    const silver = new Uint16Array(n), gold = new Uint16Array(n);
    let holoSilver = 0, holoGold = 0;

    if (n > 0 && day >= 0) {
      const profile = DEFAULT_PROFILES.find(p => p.key === 'average') ?? DEFAULT_PROFILES[0];
      const params = paramsFor(DEFAULT_SHARED_PARAMS, profile);
      const weights = stickerWeights(stickers.map(s => s.price), params.rarityAlpha);
      const seed = hashSeed(`${managerId}:${this.seasonId() ?? ''}`);
      for (const p of simulateSeason(params, weights, this.timeline(), seed)) {
        if (p.day > day) break;
        p.stickers.forEach((s, k) => {
          if (counts[s]++ === 0) firstDay[s] = p.day;
          const v: HoloVariant = p.holo[k];
          if (v === 'silver') { silver[s]++; holoSilver++; }
          else if (v === 'gold') { gold[s]++; holoGold++; }
        });
      }
    }

    const holo = Array.from({ length: n }, (_, i) => (gold[i] ? 'gold' : silver[i] ? 'silver' : null) as StickerHolo | null);
    return { counts, holo, firstAt: firstDay, holoSilver, holoGold };
  }

  /** Echte Sammlung aus GET /sticker/me (key = Sticker-ID im Album). */
  collectionFrom(entries: StickerCollectionEntry[]): Collection {
    const stickers = this.stickers();
    const n = stickers.length;
    const idxByKey = new Map(stickers.map(s => [s.id, s.idx]));
    const counts = new Uint16Array(n);
    const firstAt = new Float64Array(n).fill(-1);
    const holo: (StickerHolo | null)[] = new Array(n).fill(null);
    let holoSilver = 0, holoGold = 0;
    for (const e of entries) {
      const i = idxByKey.get(e.key);
      if (i === undefined) continue;
      counts[i] = e.count;
      firstAt[i] = new Date(e.first_at.replace(' ', 'T')).getTime();
      holo[i] = e.gold > 0 ? 'gold' : e.silver > 0 ? 'silver' : null;
      holoSilver += e.silver;
      holoGold += e.gold;
    }
    return { counts, holo, firstAt, holoSilver, holoGold };
  }
}

/** Stabiler 32-bit-Hash (FNV-1a) als Simulations-Seed. */
export function hashSeed(text: string): number {
  let h = 0x811c9dc5;
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return h >>> 0;
}
