import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { map } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { PACK_SOURCE_LABEL, StickerPack, StickerStatusService, packDetail } from '../../core/sticker-status.service';
import { StickerCardData, requestTiltPermission } from '../sticker-card/sticker-card.component';
import { AlbumClub, DEFAULT_SHARED_PARAMS, Sticker } from './album.model';
import { stickerWeights } from '../sticker-sim';
import { AlbumSlot } from './album-club-page.component';
import { PackCard } from './pack-open-dialog.component';
import { ALBUM_SOURCE, StickerAlbumService } from './sticker-album.service';
import { seasonTheme } from './season-theme';

/**
 * Sammelalbum (/klebrigsten/sammelalbum): Seite 0 = Übersicht, danach eine Seite je Verein
 * (Vorsaison-Reihenfolge). Aktuelle Seite als ?seite=<Kurzname> in der URL.
 * Oben: ungeöffnete Packs (GET /sticker/me) → Öffnen-Dialog.
 */
@Component({
  selector: 'app-sticker-album',
  standalone: false,
  templateUrl: './sticker-album.component.html',
  styleUrl: './sticker-album.component.scss',
  // Album aus der eingefrorenen Tabelle sticker — die Kind-Komponenten teilen sich diese Instanz
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album' }, StickerAlbumService],
})
export class StickerAlbumComponent {
  private album = inject(StickerAlbumService);
  private auth = inject(AuthService);
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  status = inject(StickerStatusService);

  loading = this.album.loading;
  error = this.album.error;
  rows = this.album.rows;

  isMaintainer = this.auth.isMaintainer();
  isAdmin = this.auth.isAdmin();
  /** Album sichtbar: Manager in einer Liga mit aktivem Feature — Maintainer immer (Demo/Test). */
  canSee = computed(() => this.isMaintainer || this.status.enabled());

  pickerOpen = signal(false);
  openCard = signal<StickerCardData | null>(null);

  theme = computed(() => seasonTheme(this.album.seasonId() ?? ''));

  collection = computed(() => this.album.collectionFrom(this.status.state()?.collection ?? []));

  // ── Packs ─────────────────────────────────────────────────────────────────
  packs = this.status.packs;
  packSummary = computed(() => {
    const counts = new Map<string, number>();
    for (const p of this.packs()) {
      const label = PACK_SOURCE_LABEL[p.source];
      counts.set(label, (counts.get(label) ?? 0) + 1);
    }
    return [...counts].map(([label, n]) => (n > 1 ? `${n}× ${label}` : label)).join(', ');
  });
  opened = signal<{ title: string; cards: PackCard[]; test?: boolean } | null>(null);
  packBusy = signal(false);
  packError = signal<string | null>(null);

  packListOpen = signal(false);
  readonly packLabel = PACK_SOURCE_LABEL;
  readonly detail = packDetail;

  openNextPack(): void {
    const pack = this.packs()[0];
    if (!pack || this.packBusy()) return;
    requestTiltPermission(); // synchron in der Klick-Geste (iOS), falls danach eine Karte groß geöffnet wird
    this.openPack(pack);
  }

  /** Ein bestimmtes Pack aus der Detail-Liste öffnen. */
  openSpecificPack(pack: StickerPack): void {
    if (this.packBusy()) return;
    requestTiltPermission();
    this.openPack(pack);
  }

  private openPack(pack: Pick<StickerPack, 'id' | 'source' | 'league_name'>): void {
    this.packBusy.set(true);
    this.packError.set(null);
    const before = this.collection().counts;
    const byKey = new Map(this.album.stickers().map(s => [s.id, s]));
    this.status.openPack(pack.id).subscribe({
      next: res => {
        const seen = new Map<number, number>();
        const cards: PackCard[] = [];
        for (const c of res.cards) {
          const s = byKey.get(c.key);
          if (!s) continue;
          const n = (seen.get(s.idx) ?? before[s.idx]) + 1;
          seen.set(s.idx, n);
          cards.push({ sticker: s, card: this.album.cardData(s, c.holo), isNew: c.is_new, count: n });
        }
        const title = PACK_SOURCE_LABEL[pack.source] + (pack.league_name ? ` · ${pack.league_name}` : '');
        this.opened.set({ title, cards });
        this.packBusy.set(false);
      },
      error: err => {
        this.packBusy.set(false);
        this.packError.set(err?.error?.message ?? 'Pack konnte nicht geöffnet werden');
      },
    });
  }

  closePack(): void {
    this.opened.set(null);
  }

  openPulled(c: PackCard): void {
    requestTiltPermission();
    this.openCard.set(c.card);
  }

  // ── Admin: Album einfrieren/ergänzen + Test-Pack ──────────────────────────
  syncBusy = signal(false);
  syncResult = signal<string | null>(null);

  /**
   * Test-Pack zum Ausprobieren der Öffnen-Animation: 3 Sticker werden nur im Browser gewürfelt (gleiche
   * Gewichtung/Holo-Chancen wie serverseitig) — nichts wird gespeichert, die Sammlung bleibt unverändert.
   */
  openTestPack(): void {
    const stickers = this.album.stickers();
    if (stickers.length === 0) return;
    requestTiltPermission(); // synchron in der Klick-Geste (iOS)
    const rules = DEFAULT_SHARED_PARAMS;
    const weights = stickerWeights(stickers.map(s => s.price), rules.rarityAlpha);
    const counts = this.collection().counts;
    const seen = new Map<number, number>();
    const cards: PackCard[] = [];
    for (let k = 0; k < 3; k++) {
      let r = Math.random();
      let i = 0;
      while (i < weights.length - 1 && (r -= weights[i]) > 0) i++;
      const s = stickers[i];
      const h = Math.random();
      const holo = h < rules.holoGoldChance ? 'gold' : h < rules.holoGoldChance + rules.holoSilverChance ? 'silver' : null;
      const n = (seen.get(i) ?? counts[i]) + 1;
      seen.set(i, n);
      cards.push({ sticker: s, card: this.album.cardData(s, holo), isNew: n === 1, count: n });
    }
    this.opened.set({ title: 'Test-Pack (nicht gespeichert)', cards, test: true });
  }

  syncAlbum(): void {
    if (this.syncBusy()) return;
    this.syncBusy.set(true);
    this.syncResult.set(null);
    this.api.post<{ added: number; total: number }>('sticker/album/sync').subscribe({
      next: res => {
        this.syncBusy.set(false);
        this.syncResult.set(res.added > 0 ? `${res.added} Sticker hinzugefügt (${res.total} insgesamt)` : `Album ist aktuell (${res.total} Sticker)`);
        this.album.reload();
        this.status.refresh();
      },
      error: err => {
        this.syncBusy.set(false);
        this.syncResult.set(err?.error?.message ?? 'Abgleich fehlgeschlagen');
      },
    });
  }

  // ── Seiten ────────────────────────────────────────────────────────────────
  private seite = toSignal(this.route.queryParamMap.pipe(map(p => p.get('seite'))), { initialValue: null });

  /** 0 = Übersicht, 1..n = Vereinsseiten. */
  pageIndex = computed(() => {
    const key = this.seite()?.toLowerCase();
    if (!key) return 0;
    const i = this.rows().findIndex(r => this.pageKey(r.club).toLowerCase() === key);
    return i >= 0 ? i + 1 : 0;
  });

  pageCount = computed(() => this.rows().length + 1);
  currentRow = computed(() => {
    const i = this.pageIndex();
    return i > 0 ? this.rows()[i - 1] ?? null : null;
  });

  /** Seitenliste (Desktop links / Mobile-Auswahl) mit Fortschritt je Verein. */
  pages = computed(() => {
    const col = this.collection();
    return this.rows().map((r, i) => ({
      index: i + 1,
      club: r.club,
      logo: this.album.clubLogoUrl(r.club),
      have: r.stickers.filter(s => col.counts[s.idx] > 0).length,
      total: r.stickers.length,
    }));
  });

  overviewProgress = computed(() => {
    const col = this.collection();
    const all = this.album.stickers();
    return { have: all.filter(s => col.counts[s.idx] > 0).length, total: all.length };
  });

  pageKey(c: AlbumClub): string {
    return c.short_name || c.name;
  }

  goTo(index: number): void {
    const i = Math.max(0, Math.min(index, this.pageCount() - 1));
    const row = i > 0 ? this.rows()[i - 1] : null;
    this.pickerOpen.set(false);
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { seite: row ? this.pageKey(row.club) : null },
      replaceUrl: true,
    });
    window.scrollTo({ top: 0 });
  }

  prev(): void { if (this.pageIndex() > 0) this.goTo(this.pageIndex() - 1); }
  next(): void { if (this.pageIndex() < this.pageCount() - 1) this.goTo(this.pageIndex() + 1); }

  /** Desktop: mit den Pfeiltasten blättern (nicht bei offenem Dialog / in Eingabefeldern). */
  @HostListener('document:keydown', ['$event'])
  onKey(e: KeyboardEvent): void {
    if (this.openCard() || this.opened() || e.altKey || e.ctrlKey || e.metaKey) return;
    const el = e.target as HTMLElement | null;
    if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
    if (e.key === 'ArrowLeft') { this.prev(); e.preventDefault(); }
    else if (e.key === 'ArrowRight') { this.next(); e.preventDefault(); }
  }

  // ── Große Karte ───────────────────────────────────────────────────────────
  openSlot(slot: AlbumSlot): void {
    if (!slot.card) return;
    requestTiltPermission(); // muss synchron in der Klick-Geste passieren (iOS)
    this.openCard.set(slot.card);
  }

  openSticker(s: Sticker): void {
    const col = this.collection();
    if (!col.counts[s.idx]) return;
    requestTiltPermission();
    this.openCard.set(this.album.cardData(s, col.holo[s.idx]));
  }
}
