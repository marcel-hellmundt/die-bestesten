import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../core/api.service';
import {
  MIN_PRICE, SimParams, Timeline, countsAtDay, simulateSeason, stickerWeights,
} from './sticker-sim';

interface AlbumPlayer { id: string; displayname: string; position: string | null; price: number | null; photo_uploaded: boolean; }
interface AlbumClub { id: string; name: string; short_name: string; logo_uploaded: boolean; players: AlbumPlayer[]; }
interface AlbumPreview {
  season_id: string | null;
  cutoff_date: string | null;
  matchdays: { number: number; kickoff_date: string }[];
  clubs: AlbumClub[];
}

/** Sticker im flachen Album (Index = Position in weights/counts). */
interface Sticker extends AlbumPlayer { idx: number; clubIdx: number; tier: Tier; }
type Tier = 'common' | 'rare' | 'epic' | 'legendary';

const DAY_MS = 86_400_000;
const FALLBACK_DAYS = 255;

@Component({
  selector: 'app-sticker-simulation',
  standalone: false,
  templateUrl: './sticker-simulation.component.html',
  styleUrl: './sticker-simulation.component.scss',
})
export class StickerSimulationComponent {
  private api = inject(ApiService);

  private state = toSignal(
    this.api.get<AlbumPreview>('sticker/album_preview').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: null as AlbumPreview | null, loading: true, error: null as string | null }),
      catchError(() => of({ data: null as AlbumPreview | null, loading: false, error: 'Album konnte nicht geladen werden' })),
    ),
    { initialValue: { data: null as AlbumPreview | null, loading: true, error: null as string | null } },
  );
  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);

  // ── Album ─────────────────────────────────────────────────────────────────
  clubs = computed(() => this.state().data?.clubs ?? []);

  stickers = computed<Sticker[]>(() => {
    const out: Sticker[] = [];
    this.clubs().forEach((c, clubIdx) =>
      c.players.forEach(p => out.push({ ...p, idx: out.length, clubIdx, tier: this.tierOf(p.price) })),
    );
    return out;
  });

  /** Je Verein die Sticker-Indizes (für die Zeilen). */
  rows = computed(() => {
    const byClub = this.clubs().map(c => ({ club: c, stickers: [] as Sticker[] }));
    for (const s of this.stickers()) byClub[s.clubIdx].stickers.push(s);
    return byClub;
  });

  private tierOf(price: number | null): Tier {
    const p = price ?? MIN_PRICE;
    if (p > 5_000_000) return 'legendary';
    if (p > 2_500_000) return 'epic';
    if (p > 1_000_000) return 'rare';
    return 'common';
  }

  // ── Zeitachse: Stichtag bis Auswertung des letzten Spieltags ──────────────
  private cutoff = computed(() => {
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

  // ── Parameter ─────────────────────────────────────────────────────────────
  params = signal<SimParams>({
    dailyPackSize: 3,
    loginChance: 0.95,
    guaranteeNew: true,
    milestoneInterval: 100,
    milestonePackSize: 3,
    avgPoints: 33,
    bestPackSize: 3,
    teamCount: 12,
    rarityAlpha: 0.5,
  });
  seed = signal(1);

  setParam<K extends keyof SimParams>(key: K, value: SimParams[K]): void {
    this.params.update(p => ({ ...p, [key]: value }));
  }
  num(event: Event): number { return Number((event.target as HTMLInputElement).value); }
  checked(event: Event): boolean { return (event.target as HTMLInputElement).checked; }

  reroll(): void { this.seed.set(Math.floor(Math.random() * 1e9)); }

  weights = computed(() => stickerWeights(this.stickers().map(s => s.price), this.params().rarityAlpha));

  /** Komplette Saison — wird bei jeder Parameter-/Seed-Änderung neu berechnet, der Tag-Regler scrubbt nur. */
  packs = computed(() => simulateSeason(this.params(), this.weights(), this.timeline(), this.seed()));

  rarestIdx = computed(() => { const w = this.weights(); return w.indexOf(Math.min(...w)); });
  commonestIdx = computed(() => { const w = this.weights(); return w.indexOf(Math.max(...w)); });

  /** "1:X" — Chance, diesen Sticker bei einem einzelnen gezogenen Sticker zu bekommen. */
  odds(idx: number): string {
    const w = this.weights()[idx];
    return w ? `1:${Math.round(1 / w).toLocaleString('de-DE')}` : '–';
  }

  // ── Wiedergabe ────────────────────────────────────────────────────────────
  day = signal(0);
  playing = signal(false);
  speed = signal(10); // Tage pro Sekunde
  readonly speeds = [1, 5, 10, 30, 60];
  private timer: ReturnType<typeof setInterval> | null = null;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.stopTimer());
  }

  togglePlay(): void {
    if (this.playing()) { this.pause(); return; }
    if (this.day() >= this.timeline().days) this.day.set(0);
    this.playing.set(true);
    this.startTimer();
  }

  pause(): void { this.playing.set(false); this.stopTimer(); }

  setSpeed(s: number): void {
    this.speed.set(s);
    if (this.playing()) { this.stopTimer(); this.startTimer(); }
  }

  scrub(day: number): void { this.pause(); this.day.set(day); }
  jumpToEnd(): void { this.pause(); this.day.set(this.timeline().days); }

  private startTimer(): void {
    this.timer = setInterval(() => {
      const next = this.day() + 1;
      if (next > this.timeline().days) { this.pause(); return; }
      this.day.set(next);
    }, Math.max(16, Math.round(1000 / this.speed())));
  }
  private stopTimer(): void { if (this.timer) { clearInterval(this.timer); this.timer = null; } }

  // ── Zustand am gewählten Tag ──────────────────────────────────────────────
  counts = computed(() => countsAtDay(this.packs(), this.stickers().length, this.day()));

  /** Sticker, die am aktuellen Tag erstmals gezogen wurden (Hervorhebung). */
  newToday = computed(() => {
    const before = countsAtDay(this.packs(), this.stickers().length, this.day() - 1);
    const now = this.counts();
    const fresh = new Set<number>();
    now.forEach((c, i) => { if (c > 0 && before[i] === 0) fresh.add(i); });
    return fresh;
  });

  stats = computed(() => {
    const d = this.day();
    const counts = this.counts();
    const n = counts.length;
    let unique = 0, total = 0;
    for (const c of counts) { total += c; if (c) unique++; }
    const bySource = { daily: 0, milestone: 0, best: 0 };
    for (const p of this.packs()) { if (p.day > d) break; bySource[p.source]++; }
    const complete = this.rows().filter(r => r.stickers.length > 0 && r.stickers.every(s => counts[s.idx] > 0)).length;
    return {
      unique, total, n,
      pct: n ? unique / n : 0,
      duplicates: total - unique,
      packs: bySource.daily + bySource.milestone + bySource.best,
      bySource,
      complete,
    };
  });

  clubProgress(stickers: Sticker[]): number {
    const counts = this.counts();
    return stickers.reduce((n, s) => n + (counts[s.idx] > 0 ? 1 : 0), 0);
  }

  // ── Monte-Carlo über viele Saisons (gleiche Parameter, verschiedene Seeds) ─
  readonly mcRuns = 200;
  monteCarlo = signal<{ albumP10: number; albumP50: number; albumP90: number; anyClub: number; avgClubs: number; full: number; packs: number } | null>(null);
  mcBusy = signal(false);

  runMonteCarlo(): void {
    this.mcBusy.set(true);
    // setTimeout, damit der "läuft…"-Zustand vor der synchronen Rechnung gerendert wird
    setTimeout(() => {
      const params = this.params(), weights = this.weights(), timeline = this.timeline();
      const n = this.stickers().length;
      const rows = this.rows();
      const album: number[] = [], clubs: number[] = [], packsN: number[] = [];
      let full = 0;
      for (let r = 0; r < this.mcRuns; r++) {
        const packs = simulateSeason(params, weights, timeline, 1000 + r * 7919);
        const counts = countsAtDay(packs, n, timeline.days);
        let unique = 0;
        for (const c of counts) if (c) unique++;
        album.push(n ? unique / n : 0);
        clubs.push(rows.filter(row => row.stickers.length > 0 && row.stickers.every(s => counts[s.idx] > 0)).length);
        packsN.push(packs.length);
        if (unique === n) full++;
      }
      const q = (arr: number[], p: number) => [...arr].sort((a, b) => a - b)[Math.min(arr.length - 1, Math.floor(p * arr.length))];
      this.monteCarlo.set({
        albumP10: q(album, 0.1), albumP50: q(album, 0.5), albumP90: q(album, 0.9),
        anyClub: clubs.filter(c => c > 0).length / this.mcRuns,
        avgClubs: clubs.reduce((a, b) => a + b, 0) / this.mcRuns,
        full: full / this.mcRuns,
        packs: packsN.reduce((a, b) => a + b, 0) / this.mcRuns,
      });
      this.mcBusy.set(false);
    });
  }

  // ── Bilder ────────────────────────────────────────────────────────────────
  clubLogoUrl(c: AlbumClub): string {
    return c.logo_uploaded ? `https://img.die-bestesten.de/club/${c.id}.png` : 'img/placeholders/club.png';
  }

  priceLabel(price: number | null): string {
    return price == null ? 'kein Marktwert' : (price / 1e6).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' Mio';
  }

  readonly tierLabel: Record<Tier, string> = { common: 'Häufig', rare: 'Selten', epic: 'Episch', legendary: 'Legendär' };
  readonly tiers: Tier[] = ['common', 'rare', 'epic', 'legendary'];
  readonly positionLabel: Record<string, string> = { GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU' };
}
