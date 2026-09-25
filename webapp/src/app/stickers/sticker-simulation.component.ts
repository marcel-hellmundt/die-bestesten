import { Component, DestroyRef, HostListener, computed, inject, signal } from '@angular/core';
import { SimParams, SimProfile, countsAtDay, holoAtDay, simulateSeason, stickerWeights } from './sticker-sim';
import { StickerCardData, StickerHolo, requestTiltPermission } from './sticker-card/sticker-card.component';
import { StickerAlbumService } from './album/sticker-album.service';
import {
  AlbumClub, DEFAULT_PROFILES, DEFAULT_SHARED_PARAMS, POSITION_LABEL, SharedParams, Sticker,
  TIERS, TIER_LABEL, TIER_RANGE, paramsFor,
} from './album/album.model';

@Component({
  selector: 'app-sticker-simulation',
  standalone: false,
  templateUrl: './sticker-simulation.component.html',
  styleUrl: './sticker-simulation.component.scss',
})
export class StickerSimulationComponent {
  private album = inject(StickerAlbumService);

  loading = this.album.loading;
  error   = this.album.error;

  // ── Album (gemeinsam mit dem Sammelalbum, inkl. Wappen- + Stadion-Sticker je Club) ──
  stickers   = this.album.stickers;
  rows       = this.album.rows;
  tierCounts = this.album.tierCounts;
  timeline   = this.album.timeline;
  dateOf(day: number): Date | null { return this.album.dateOf(day); }

  // ── Parameter ─────────────────────────────────────────────────────────────
  /** Für alle Manager gleiche Regeln (Pack-Größen, Meilensteine, Seltenheit, Holo). */
  shared = signal<SharedParams>({ ...DEFAULT_SHARED_PARAMS });

  profiles = signal<SimProfile[]>(DEFAULT_PROFILES.map(p => ({ ...p })));
  profileKey = signal('average');
  profile = computed(() => this.profiles().find(p => p.key === this.profileKey()) ?? this.profiles()[0]);

  /** Effektive Parameter für die abgespielte Saison = gemeinsame Regeln + gewähltes Profil. */
  params = computed<SimParams>(() => paramsFor(this.shared(), this.profile()));

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
  /** Holo-Karten je Sticker am gewählten Tag (Silber/Gold getrennt). */
  holo = computed(() => holoAtDay(this.packs(), this.stickers().length, this.day()));

  /** Beste bisher gesammelte Variante eines Stickers: gold > silver > normal. */
  bestHolo(idx: number): StickerHolo | null {
    const h = this.holo();
    return h.gold[idx] > 0 ? 'gold' : h.silver[idx] > 0 ? 'silver' : null;
  }

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
    const h = this.holo();
    const holoSilver = h.silver.reduce((a, b) => a + b, 0);
    const holoGold = h.gold.reduce((a, b) => a + b, 0);
    return {
      unique, total, n,
      pct: n ? unique / n : 0,
      duplicates: total - unique,
      packs: bySource.daily + bySource.milestone + bySource.best,
      bySource,
      complete,
      holoSilver, holoGold,
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
    stickers: number; holoSilver: number; holoGold: number; anyGold: number;
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
        const params = paramsFor(this.shared(), profile);
        const album: number[] = [], clubs: number[] = [], packsN: number[] = [], firstDays: number[] = [];
        let full = 0, stickersSum = 0, silverSum = 0, goldSum = 0, anyGold = 0;
        for (let r = 0; r < this.mcRuns; r++) {
          const packs = simulateSeason(params, weights, timeline, 1000 + r * 7919);
          const counts = countsAtDay(packs, n, timeline.days);
          let unique = 0;
          for (const c of counts) if (c) unique++;
          let gold = 0;
          for (const p of packs) {
            stickersSum += p.stickers.length;
            for (const v of p.holo) { if (v === 'silver') silverSum++; else if (v === 'gold') gold++; }
          }
          goldSum += gold;
          if (gold > 0) anyGold++;
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
          stickers: stickersSum / this.mcRuns,
          holoSilver: silverSum / this.mcRuns,
          holoGold: goldSum / this.mcRuns,
          anyGold: anyGold / this.mcRuns,
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

  /** Vorschaubild im Tooltip: Spielerfoto, Wappen bzw. Stadion. */
  photoUrl(s: Sticker): string | null { return this.album.stickerImageUrl(s); }

  // ── Sticker-Karte (Test) ──────────────────────────────────────────────────
  openCardIdx = signal<number | null>(null);
  /** Test-Auswahl: Variante der geöffneten Karte — "collected" = beste gesammelte (Gold > Silber > normal). */
  openAs = signal<'collected' | 'normal' | StickerHolo>('collected');
  readonly openAsOptions: { value: 'collected' | 'normal' | StickerHolo; label: string }[] = [
    { value: 'collected', label: 'wie gesammelt' },
    { value: 'normal', label: 'Normal' },
    { value: 'silver', label: 'Holo Silber' },
    { value: 'gold', label: 'Holo Gold' },
  ];
  setOpenAs(event: Event): void { this.openAs.set((event.target as HTMLSelectElement).value as any); }

  openCardData = computed<StickerCardData | null>(() => {
    const idx = this.openCardIdx();
    if (idx === null) return null;
    const as = this.openAs();
    const holo = as === 'collected' ? this.bestHolo(idx) : as === 'normal' ? null : as;
    return this.album.cardData(this.stickers()[idx], holo);
  });

  openCard(idx: number): void {
    requestTiltPermission(); // muss synchron in der Klick-Geste passieren (iOS)
    this.tip.set(null);
    this.pause();
    this.openCardIdx.set(idx);
  }

  // ── Anzeige ───────────────────────────────────────────────────────────────
  clubLogoUrl(c: AlbumClub): string { return this.album.clubLogoUrl(c); }

  priceLabel(s: Sticker): string {
    if (s.kind !== 'player') return s.kind === 'logo' ? 'Vereins-Sticker (Wappen)' : 'Vereins-Sticker (Stadion)';
    return s.price == null ? 'kein Marktwert' : (s.price / 1e6).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' Mio';
  }

  readonly tierLabel = TIER_LABEL;
  readonly tierRange = TIER_RANGE;
  readonly tiers = TIERS;
  readonly positionLabel = POSITION_LABEL;
}
