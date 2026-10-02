import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import {
  OtherCollection, PACK_SOURCE_LABEL, StickerPack, StickerStatusService, packDetail,
} from '../../core/sticker-status.service';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { AlbumClub, Sticker, isLight } from './album.model';
import { AlbumSlot } from './album-club-page.component';
import { PackCard, PackInfo, packInfo, packRules } from './pack.model';
import { EUR_OFFERS, EUR_STARTER, PACK_KINDS, ShopOffer, offerKinds, packsOf } from '../shop/shop.model';
import { PackOpener } from './pack-opener';
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
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album' }, StickerAlbumService, PackOpener],
})
export class StickerAlbumComponent {
  private album = inject(StickerAlbumService);
  opener = inject(PackOpener);
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

  // ── Wessen Album? (/klebrigsten/klebebande/:managerId; sonst bzw. eigene ID = eigenes Album) ─
  readonly myId = this.auth.getManagerId();
  private managerParam = toSignal(this.route.paramMap.pipe(map(p => p.get('managerId'))), { initialValue: null });
  /** ID des angezeigten fremden Albums, null = eigenes */
  viewId = computed(() => { const id = this.managerParam(); return id && id !== this.myId ? id : null; });
  isOwn = computed(() => this.viewId() === null);

  private other = toSignal(
    toObservable(this.viewId).pipe(
      switchMap(id => !id ? of(null) : this.api.get<OtherCollection>(`sticker/collection/${id}`).pipe(
        map(data => ({ data, error: null as string | null })),
        catchError(err => of({ data: null, error: (err?.error?.message as string) ?? 'Album konnte nicht geladen werden' })),
        startWith({ data: null, error: null }),
      )),
    ),
    { initialValue: null },
  );
  otherName = computed(() => this.other()?.data?.manager_name ?? null);
  otherError = computed(() => this.other()?.error ?? null);

  collection = computed(() => this.isOwn()
    ? this.album.collectionFrom(this.status.state()?.collection ?? [])
    : this.album.collectionFrom(this.other()?.data?.collection ?? []));

  /** Karten aus noch unbezahlten Euro-Käufen (bis zur Bestätigung nicht tauschbar) — nur eigenes Album */
  lockedCount = computed(() => this.isOwn() ? (this.status.state()?.collection ?? []).reduce((n, e) => n + (e.locked ?? 0), 0) : 0);

  /** Tausch-Dialog mit dem Besitzer des angezeigten fremden Albums */
  tradeOpen = signal(false);

  /** Zum eigenen Album wechseln — die aktuelle Seite (?seite) bleibt erhalten. */
  viewOwnAlbum(): void {
    this.router.navigate(['/klebrigsten/sammelalbum'], { queryParamsHandling: 'preserve' });
  }

  // ── Umschalter "< Managername >": durch die Alben aller Sammler blättern (Reihenfolge wie in der Klebebande) ─
  private collectors = toSignal(
    this.api.get<{ collectors: { manager_id: string; manager_name: string }[] }>('sticker/collectors').pipe(
      map(res => res.collectors ?? []),
      catchError(() => of([] as { manager_id: string; manager_name: string }[])),
    ),
    { initialValue: [] as { manager_id: string; manager_name: string }[] },
  );
  private collectorIndex = computed(() => {
    const id = this.viewId() ?? this.myId;
    return this.collectors().findIndex(c => c.manager_id === id);
  });
  /** Name des angezeigten Albums für den Umschalter (null → Umschalter ausblenden) */
  switcherName = computed(() => {
    if (this.collectors().length < 2) return null;
    const i = this.collectorIndex();
    return i >= 0 ? this.collectors()[i].manager_name : (this.otherName() ?? null);
  });

  /** Vorheriges/nächstes Album (am Ende wieder von vorn); die aktuelle Seite (?seite) bleibt erhalten */
  stepAlbum(dir: -1 | 1): void {
    const list = this.collectors();
    if (list.length < 2) return;
    const i = this.collectorIndex();
    const target = list[((i < 0 ? 0 : i + dir) + list.length) % list.length];
    const path = target.manager_id === this.myId
      ? ['/klebrigsten/sammelalbum']
      : ['/klebrigsten/klebebande', target.manager_id];
    this.router.navigate(path, { queryParamsHandling: 'preserve' });
  }

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
  packListOpen = signal(false);
  readonly packLabel = PACK_SOURCE_LABEL;
  readonly detail = packDetail;

  /** Ältestes ungeöffnetes Pack zeigen (geschlossen — geöffnet wird erst beim Aufreißen). */
  openNextPack(): void {
    const pack = this.packs()[0];
    if (!pack) return;
    this.opener.show(packInfo(pack));
  }

  /** Ein bestimmtes Pack aus der Detail-Liste zeigen. */
  openSpecificPack(pack: StickerPack): void {
    this.opener.show(packInfo(pack));
  }

  openPulled(c: PackCard): void {
    this.openCard.set(c.card);
  }

  // ── Admin: Album einfrieren/ergänzen + Test ───────────────────────────────
  syncBusy = signal(false);
  syncResult = signal<string | null>(null);

  /** Test läuft (Überschrift "nicht gespeichert") */
  testing = signal(false);

  /**
   * Test: n verschiedene neue Packs (Tages-Pack, Meilensteine, Spieltagssieger) — jedes mit eigenem Anlass,
   * damit sie in der Auswahl nebeneinander stehen statt gestapelt. Nur im Browser gewürfelt, nichts gespeichert.
   */
  openTestMixed(n: number): void {
    const base = 100 * (1 + Math.floor(Math.random() * 10));
    const matchday = 1 + Math.floor(Math.random() * 30);
    const all: PackInfo[] = [
      { id: null, source: 'daily', size: packRules('daily').size, milestonePoints: null, matchdayNumber: null, leagueName: null },
      { id: null, source: 'milestone', size: packRules('milestone').size, milestonePoints: base, matchdayNumber: null, leagueName: 'Test-Liga' },
      { id: null, source: 'matchday_best', size: packRules('matchday_best').size, milestonePoints: null, matchdayNumber: matchday, leagueName: 'Test-Liga' },
      { id: null, source: 'milestone', size: packRules('milestone').size, milestonePoints: base + 100, matchdayNumber: null, leagueName: 'Test-Liga' },
    ];
    this.testing.set(true);
    this.opener.showChoice(all.slice(0, n));
  }

  /** Test-Buttons = Euro-Angebote mit mehreren Packs, genau wie im Shop (Starter, Handvoll, Stapel, Kiste) */
  readonly testOffers = [EUR_STARTER, ...EUR_OFFERS].filter(o => packsOf(o) > 1);
  readonly packsOf = packsOf;

  /**
   * Test: ein Euro-Kauf wie in der Einblendung zur Auswahl — je Pack-Art die Packs des Angebots (Vereins-Pack
   * mit zufälligem Verein). Karten werden erst beim Aufreißen und nur im Browser gewürfelt, nichts wird gespeichert.
   */
  openTestPacks(offer: ShopOffer): void {
    const clubs = this.rows().map(r => r.club.id);
    const clubId = clubs[Math.floor(Math.random() * clubs.length)] ?? null;
    const packs: PackInfo[] = offerKinds(offer).flatMap(({ kind, count }) =>
      Array.from({ length: count }, () => ({
        id: null, source: 'shop' as const, size: PACK_KINDS[kind].size, kind, shopOffer: offer.key,
        clubId: PACK_KINDS[kind].club ? clubId : null,
        milestonePoints: null, matchdayNumber: null, leagueName: null,
      })));
    this.testing.set(true);
    this.opener.showChoice(packs);
  }

  closeOpener(): void {
    this.opener.close();
    this.testing.set(false);
  }

  syncAlbum(): void {
    if (this.syncBusy()) return;
    this.syncBusy.set(true);
    this.syncResult.set(null);
    this.api.post<{ added: number; total: number; packs?: { milestone: number; matchday_best: number } }>('sticker/album/sync').subscribe({
      next: res => {
        this.syncBusy.set(false);
        const album = res.added > 0 ? `${res.added} Sticker hinzugefügt (${res.total} insgesamt)` : `Album ist aktuell (${res.total} Sticker)`;
        const m = res.packs?.milestone ?? 0, b = res.packs?.matchday_best ?? 0;
        // rückwirkend vergebene Packs (bisherige Meilensteine + Spieltagssiege der Saison, alle Ligen mit Feature)
        const packs = m + b > 0 ? ` · nachvergeben: ${m} Meilenstein-, ${b} Spieltagssieger-Packs` : '';
        this.syncResult.set(album + packs);
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
      // Schrift auf der Vereinsfarbe (ausgewählt): weiß, bei (fast) weißer Vereinsfarbe schwarz
      ink: r.club.primary_color && isLight(r.club.primary_color) ? '#000' : '#fff',
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
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    window.scrollTo({ top: 0 });
  }

  prev(): void { if (this.pageIndex() > 0) this.goTo(this.pageIndex() - 1); }
  next(): void { if (this.pageIndex() < this.pageCount() - 1) this.goTo(this.pageIndex() + 1); }

  /** Desktop: mit den Pfeiltasten blättern (nicht bei offenem Dialog / in Eingabefeldern). */
  @HostListener('document:keydown', ['$event'])
  onKey(e: KeyboardEvent): void {
    if (this.openCard() || this.opener.visible() || this.tradeOpen() || e.altKey || e.ctrlKey || e.metaKey) return;
    const el = e.target as HTMLElement | null;
    if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
    if (e.key === 'ArrowLeft') { this.prev(); e.preventDefault(); }
    else if (e.key === 'ArrowRight') { this.next(); e.preventDefault(); }
  }

  // ── Große Karte ───────────────────────────────────────────────────────────
  openSlot(slot: AlbumSlot): void {
    if (!slot.card) return;
    this.openCard.set(slot.card);
  }

  openSticker(s: Sticker): void {
    const col = this.collection();
    if (!col.counts[s.idx]) return;
    this.openCard.set(this.album.cardData(s, col.holo[s.idx]));
  }
}
