import { Component, DestroyRef, HostListener, computed, inject, signal } from '@angular/core';
import {
  MIN_PRICE, ShopPlan, ShopTiming, SimParams, SimProfile, countsAtDay, holoAtDay, shopPlan, simulateSeason, stickerWeights,
} from './sticker-sim';
import { StickerCardData, StickerHolo, requestTiltPermission } from './sticker-card/sticker-card.component';
import { ALBUM_SOURCE, StickerAlbumService } from './album/sticker-album.service';
import {
  AlbumClub, DEFAULT_PROFILES, DEFAULT_SHARED_PARAMS, DEFAULT_TIER_THRESHOLDS, POSITION_LABEL, SharedParams, Sticker,
  TIERS, TIER_LABEL, Tier, TierThresholds, paramsFor, tierRanges,
} from './album/album.model';

/** Monte-Carlo-Kennzahlen eines Parametersatzes. */
interface McResult {
  albumP10: number; albumP50: number; albumP90: number;
  anyClub: number; avgClubs: number; full: number; packs: number; firstClubDay: number | null;
  stickers: number; duplicates: number; holoSilver: number; holoGold: number; anyGold: number;
}

@Component({
  selector: 'app-sticker-simulation',
  standalone: false,
  templateUrl: './sticker-simulation.component.html',
  styleUrl: './sticker-simulation.component.scss',
  // Simulation arbeitet auf dem live berechneten Album (funktioniert auch vor dem Einfrieren)
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album_preview' }, StickerAlbumService],
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

  // Gewichte hängen nur an Marktwert + α: Grenzen der Seltenheitsstufen ändern zwar die Sticker-Liste,
  // dürfen die Saison aber nicht neu simulieren lassen → Vergleich per Inhalt statt Referenz
  weights = computed(
    () => stickerWeights(this.stickers().map(s => s.price), this.shared().rarityAlpha),
    { equal: (a, b) => a.length === b.length && a.every((w, i) => w === b[i]) },
  );

  // ── Seltenheitsstufen (nur Anzeige, gelten nur auf dieser Seite) ──────────
  thresholds = this.album.tierThresholds;
  readonly thresholdStep = 100_000;
  readonly thresholdMin = MIN_PRICE;
  /** Obergrenze der Regler: höchster Marktwert im Album, auf ganze Mio aufgerundet. */
  thresholdMax = computed(() => {
    const max = Math.max(0, ...this.stickers().map(s => s.price ?? 0));
    return Math.max(10, Math.ceil(max / 1e6)) * 1e6;
  });
  thresholdsChanged = computed(() => {
    const t = this.thresholds(), d = DEFAULT_TIER_THRESHOLDS;
    return t.rare !== d.rare || t.epic !== d.epic || t.legendary !== d.legendary;
  });

  /** Grenze setzen — bleibt zwischen den Nachbar-Grenzen, damit die Reihenfolge Selten < Episch < Legendär gilt. */
  setThreshold(key: keyof TierThresholds, value: number): void {
    const t = this.thresholds(), step = this.thresholdStep;
    const lo = key === 'rare' ? this.thresholdMin : key === 'epic' ? t.rare + step : t.epic + step;
    const hi = key === 'rare' ? t.epic - step : key === 'epic' ? t.legendary - step : this.thresholdMax();
    const snapped = Math.round(value / step) * step; // Regler liefert Mio-Gleitkommawerte (1.1 * 1e6 ≠ 1_100_000)
    this.thresholds.set({ ...t, [key]: Math.min(Math.max(snapped, lo), hi) });
  }
  resetThresholds(): void { this.thresholds.set({ ...DEFAULT_TIER_THRESHOLDS }); }

  /** Je Stufe: Spieler- und Vereins-Sticker, Anteil am Album und Chance je gezogenem Sticker. */
  tierStats = computed(() => {
    const w = this.weights();
    const out = Object.fromEntries(TIERS.map(t => [t, { players: 0, clubs: 0, total: 0, share: 0, drawShare: 0 }])) as
      Record<Tier, { players: number; clubs: number; total: number; share: number; drawShare: number }>;
    const stickers = this.stickers();
    for (const s of stickers) {
      const e = out[s.tier];
      if (s.kind === 'player') e.players++; else e.clubs++;
      e.total++;
      e.drawShare += w[s.idx] ?? 0;
    }
    for (const t of TIERS) out[t].share = stickers.length ? out[t].total / stickers.length : 0;
    return out;
  });

  /** Vereins-Index je Sticker (für Vereins-Packs aus dem Shop) — Vergleich per Inhalt wie bei weights. */
  clubOf = computed(
    () => this.stickers().map(s => s.clubIdx),
    { equal: (a, b) => a.length === b.length && a.every((c, i) => c === b[i]) },
  );

  /** Komplette Saison — wird bei jeder Parameter-/Seed-Änderung neu berechnet, der Tag-Regler scrubbt nur. */
  packs = computed(() => simulateSeason(this.params(), this.weights(), this.timeline(), this.seed(), this.clubOf()));

  // ── Shop ──────────────────────────────────────────────────────────────────
  readonly shopTimings: { value: ShopTiming; label: string }[] = [
    { value: 'start', label: 'Saisonstart' },
    { value: 'spread', label: 'verteilt' },
    { value: 'late', label: 'Rückrunde' },
  ];
  /** Was mit den eingestellten Lukaten/Euro gekauft wird. */
  plan = computed(() => shopPlan(this.shared().shopLukaten, this.shared().shopEuro));
  planLabel(p: ShopPlan): string {
    if (p.offers.length === 0) return 'keine Käufe';
    const counts = new Map<string, number>();
    for (const o of p.offers) counts.set(o.name, (counts.get(o.name) ?? 0) + 1);
    return [...counts].map(([name, c]) => (c > 1 ? `${c}× ${name}` : name)).join(' · ');
  }
  formatEur(v: number): string { return v.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); }

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
    const bySource = { daily: 0, milestone: 0, best: 0, shop: 0 };
    for (const p of this.packs()) { if (p.day > d) break; bySource[p.source]++; }
    const complete = this.rows().filter(r => r.stickers.length > 0 && r.stickers.every(s => counts[s.idx] > 0)).length;
    const h = this.holo();
    const holoSilver = h.silver.reduce((a, b) => a + b, 0);
    const holoGold = h.gold.reduce((a, b) => a + b, 0);
    return {
      unique, total, n,
      pct: n ? unique / n : 0,
      duplicates: total - unique,
      packs: bySource.daily + bySource.milestone + bySource.best + bySource.shop,
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
  monteCarlo = signal<({ label: string } & McResult)[] | null>(null);
  mcBusy = signal(false);

  /** Alle Profile mit denselben gemeinsamen Regeln — so werden aktive und inaktive Manager direkt vergleichbar. */
  runMonteCarlo(): void {
    this.mcBusy.set(true);
    // setTimeout, damit der "läuft…"-Zustand vor der synchronen Rechnung gerendert wird
    setTimeout(() => {
      this.monteCarlo.set(this.profiles().map(profile => ({
        label: profile.label, ...this.mcStats(paramsFor(this.shared(), profile)),
      })));
      this.mcBusy.set(false);
    });
  }

  // ── Monte-Carlo: Shop-Szenarien je Profil ─────────────────────────────────
  /** Was Manager im Shop ausgeben (pro Saison) — Rest der Regeln wie eingestellt, Kaufzeitpunkt wie oben. */
  readonly shopScenarios = [
    { key: 'none', label: 'ohne Shop',            lukaten: 0,   euro: 0 },
    { key: 'luk',  label: '100 Lukaten',          lukaten: 100, euro: 0 },
    { key: 'e5',   label: '100 Lukaten + 5 €',    lukaten: 100, euro: 5 },
    { key: 'e10',  label: '100 Lukaten + 10 €',   lukaten: 100, euro: 10 },
  ];
  shopMc = signal<{ profile: string; rows: ({ key: string; label: string; plan: ShopPlan } & McResult)[] }[] | null>(null);
  shopMcBusy = signal(false);

  runShopScenarios(): void {
    this.shopMcBusy.set(true);
    setTimeout(() => {
      this.shopMc.set(this.profiles().map(profile => ({
        profile: profile.label,
        rows: this.shopScenarios.map(sc => {
          const params = { ...paramsFor(this.shared(), profile), shopLukaten: sc.lukaten, shopEuro: sc.euro };
          return { key: sc.key, label: sc.label, plan: shopPlan(sc.lukaten, sc.euro), ...this.mcStats(params) };
        }),
      })));
      this.shopMcBusy.set(false);
    });
  }

  /** Kennzahlen über mcRuns Saisons — gleiche Seeds für alle Parameter (direkt vergleichbar). */
  private mcStats(params: SimParams): McResult {
    const weights = this.weights(), timeline = this.timeline(), clubOf = this.clubOf();
    const n = this.stickers().length;
    const rows = this.rows();
    const q = (arr: number[], p: number) => [...arr].sort((a, b) => a - b)[Math.min(arr.length - 1, Math.floor(p * arr.length))];
    const album: number[] = [], clubs: number[] = [], packsN: number[] = [], firstDays: number[] = [];
    let full = 0, stickersSum = 0, silverSum = 0, goldSum = 0, anyGold = 0;
    for (let r = 0; r < this.mcRuns; r++) {
      const packs = simulateSeason(params, weights, timeline, 1000 + r * 7919, clubOf);
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
    const stickers = stickersSum / this.mcRuns;
    return {
      albumP10: q(album, 0.1), albumP50: q(album, 0.5), albumP90: q(album, 0.9),
      anyClub: clubs.filter(c => c > 0).length / this.mcRuns,
      avgClubs: clubs.reduce((a, b) => a + b, 0) / this.mcRuns,
      full: full / this.mcRuns,
      packs: packsN.reduce((a, b) => a + b, 0) / this.mcRuns,
      // Median nur, wenn in mind. der Hälfte der Saisons überhaupt ein Verein komplett wurde
      firstClubDay: firstDays.length >= this.mcRuns / 2 ? q(firstDays, 0.5) : null,
      stickers,
      duplicates: stickers - album.reduce((a, b) => a + b, 0) / this.mcRuns * n,
      holoSilver: silverSum / this.mcRuns,
      holoGold: goldSum / this.mcRuns,
      anyGold: anyGold / this.mcRuns,
    };
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
    const mio = (p: number) => (p / 1e6).toLocaleString('de-DE', { maximumFractionDigits: 2 }) + ' Mio';
    if (s.kind !== 'player') {
      return `Vereins-Sticker (${s.kind === 'logo' ? 'Wappen' : 'Stadion'}), gewichtet wie ${mio(s.price ?? 0)}`;
    }
    return s.price == null ? 'kein Marktwert' : mio(s.price);
  }

  readonly tierLabel = TIER_LABEL;
  tierRange = computed(() => tierRanges(this.thresholds()));
  readonly tiers = TIERS;
  readonly positionLabel = POSITION_LABEL;
}
