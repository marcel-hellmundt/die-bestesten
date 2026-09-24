import { Component, DestroyRef, HostListener, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../core/api.service';
import {
  MIN_PRICE, SimParams, SimProfile, Timeline, countsAtDay, simulateSeason, stickerWeights,
} from './sticker-sim';
import { StickerCardData, requestTiltPermission } from './sticker-card/sticker-card.component';

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
/** Regeln, die für alle Manager gleich sind — der Rest von SimParams kommt aus dem Profil. */
type SharedParams = Omit<SimParams, 'loginChance' | 'avgPoints' | 'bestChance'>;

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
  /** Für alle Manager gleiche Regeln (Pack-Größen, Meilensteine, Seltenheit). */
  shared = signal<SharedParams>({
    dailyPackSize: 3,
    guaranteeNew: true,
    milestoneInterval: 100,
    milestonePackSize: 3,
    bestPackSize: 3,
    rarityAlpha: 0.5,
  });

  /**
   * Manager-Typen — Startwerte aus den echten Daten: Einlog-Tage der letzten 33 Tage (manager_session),
   * Saisonpunkte der Vorsaison (679–1.455 Pkt. → 20–43 Pkt./Spieltag), Spieltagssiege (vorläufig).
   */
  profiles = signal<SimProfile[]>([
    { key: 'active',   label: 'Aktiv & stark',    loginChance: 1,    avgPoints: 43, bestChance: 0.15 },
    { key: 'average',  label: 'Durchschnitt',     loginChance: 0.95, avgPoints: 33, bestChance: 0.08 },
    { key: 'inactive', label: 'Inaktiv & schwach', loginChance: 0.36, avgPoints: 20, bestChance: 0.03 },
  ]);
  profileKey = signal('average');
  profile = computed(() => this.profiles().find(p => p.key === this.profileKey()) ?? this.profiles()[0]);

  /** Effektive Parameter für die abgespielte Saison = gemeinsame Regeln + gewähltes Profil. */
  params = computed<SimParams>(() => this.paramsFor(this.profile()));
  private paramsFor(p: SimProfile): SimParams {
    return { ...this.shared(), loginChance: p.loginChance, avgPoints: p.avgPoints, bestChance: p.bestChance };
  }

  seed = signal(1);

  setShared<K extends keyof SharedParams>(key: K, value: SharedParams[K]): void {
    this.shared.update(s => ({ ...s, [key]: value }));
  }
  setProfile<K extends 'loginChance' | 'avgPoints' | 'bestChance'>(key: K, value: number): void {
    const k = this.profileKey();
    this.profiles.update(list => list.map(p => (p.key === k ? { ...p, [key]: value } : p)));
  }
  num(event: Event): number { return Number((event.target as HTMLInputElement).value); }
  checked(event: Event): boolean { return (event.target as HTMLInputElement).checked; }

  reroll(): void { this.seed.set(Math.floor(Math.random() * 1e9)); }

  weights = computed(() => stickerWeights(this.stickers().map(s => s.price), this.shared().rarityAlpha));

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
  monteCarlo = signal<{
    label: string; albumP10: number; albumP50: number; albumP90: number;
    anyClub: number; avgClubs: number; full: number; packs: number; firstClubDay: number | null;
  }[] | null>(null);
  mcBusy = signal(false);

  /** Alle Profile mit denselben gemeinsamen Regeln — so werden aktive und inaktive Manager direkt vergleichbar. */
  runMonteCarlo(): void {
    this.mcBusy.set(true);
    // setTimeout, damit der "läuft…"-Zustand vor der synchronen Rechnung gerendert wird
    setTimeout(() => {
      const weights = this.weights(), timeline = this.timeline();
      const n = this.stickers().length;
      const rows = this.rows();
      const q = (arr: number[], p: number) => [...arr].sort((a, b) => a - b)[Math.min(arr.length - 1, Math.floor(p * arr.length))];
      const results = this.profiles().map(profile => {
        const params = this.paramsFor(profile);
        const album: number[] = [], clubs: number[] = [], packsN: number[] = [], firstDays: number[] = [];
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
          const first = this.firstCompleteClubDay(packs, n, rows);
          if (first !== null) firstDays.push(first);
        }
        return {
          label: profile.label,
          albumP10: q(album, 0.1), albumP50: q(album, 0.5), albumP90: q(album, 0.9),
          anyClub: clubs.filter(c => c > 0).length / this.mcRuns,
          avgClubs: clubs.reduce((a, b) => a + b, 0) / this.mcRuns,
          full: full / this.mcRuns,
          packs: packsN.reduce((a, b) => a + b, 0) / this.mcRuns,
          // Median nur, wenn in mind. der Hälfte der Saisons überhaupt ein Verein komplett wurde
          firstClubDay: firstDays.length >= this.mcRuns / 2 ? q(firstDays, 0.5) : null,
        };
      });
      this.monteCarlo.set(results);
      this.mcBusy.set(false);
    });
  }

  /** Tag, an dem der erste Verein komplett wurde (null = nie). */
  private firstCompleteClubDay(packs: { day: number; stickers: number[] }[], n: number, rows: { stickers: Sticker[] }[]): number | null {
    const counts = new Uint16Array(n);
    const missingPerRow = rows.map(r => r.stickers.length);
    const rowOf = new Int16Array(n);
    rows.forEach((r, ri) => r.stickers.forEach(s => (rowOf[s.idx] = ri)));
    for (const p of packs) {
      for (const s of p.stickers) {
        if (counts[s]++ === 0 && --missingPerRow[rowOf[s]] === 0) return p.day;
      }
    }
    return null;
  }

  // ── Tooltip ───────────────────────────────────────────────────────────────
  /** x/y = Viewport-Koordinaten (position: fixed); below = unter statt über dem Sticker (oben zu wenig Platz). */
  tip = signal<{ idx: number; x: number; y: number; below: boolean } | null>(null);

  showTip(event: Event, idx: number): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const halfWidth = 130; // halbe Tooltip-Breite, damit er nicht aus dem Viewport ragt
    const x = Math.min(Math.max(rect.left + rect.width / 2, halfWidth + 8), window.innerWidth - halfWidth - 8);
    const below = rect.top < 170;
    this.tip.set({ idx, x, y: below ? rect.bottom : rect.top, below });
  }

  // Beim Scrollen ausblenden (fixed-Position würde sonst vom Sticker wegdriften) — wheel/touchmove
  // zusätzlich, da der Shell-Content ggf. in einem eigenen Container statt im window scrollt.
  @HostListener('window:scroll')
  @HostListener('window:wheel')
  @HostListener('window:touchmove')
  hideTip(): void { if (this.tip()) this.tip.set(null); }

  photoUrl(s: Sticker): string | null {
    const seasonId = this.state().data?.season_id;
    return s.photo_uploaded && seasonId ? `https://img.die-bestesten.de/player/${seasonId}/${s.id}.png` : null;
  }

  // ── Sticker-Karte (Test) ──────────────────────────────────────────────────
  openCardIdx = signal<number | null>(null);

  openCardData = computed<StickerCardData | null>(() => {
    const idx = this.openCardIdx();
    if (idx === null) return null;
    const s = this.stickers()[idx];
    const club = this.rows()[s.clubIdx].club;
    return {
      displayname: s.displayname,
      photoUrl: this.photoUrl(s),
      clubLogoUrl: club.logo_uploaded ? this.clubLogoUrl(club) : null,
      clubName: club.name,
      tier: s.tier,
    };
  });

  openCard(idx: number): void {
    requestTiltPermission(); // muss synchron in der Klick-Geste passieren (iOS)
    this.tip.set(null);
    this.pause();
    this.openCardIdx.set(idx);
  }

  // ── Bilder ────────────────────────────────────────────────────────────────
  clubLogoUrl(c: AlbumClub): string {
    return c.logo_uploaded ? `https://img.die-bestesten.de/club/${c.id}.png` : 'img/placeholders/club.png';
  }

  priceLabel(price: number | null): string {
    return price == null ? 'kein Marktwert' : (price / 1e6).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' Mio';
  }

  readonly tierLabel: Record<Tier, string> = { common: 'Häufig', rare: 'Selten', epic: 'Episch', legendary: 'Legendär' };
  // Marktwert-Spannen je Tier — müssen zu tierOf() passen
  readonly tierRange: Record<Tier, string> = {
    common: '≤ 1 Mio', rare: '1–2,5 Mio', epic: '2,5–5 Mio', legendary: '> 5 Mio',
  };
  readonly tiers: Tier[] = ['common', 'rare', 'epic', 'legendary'];
  readonly positionLabel: Record<string, string> = { GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU' };
}
