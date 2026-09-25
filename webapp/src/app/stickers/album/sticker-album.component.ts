import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import {
  OtherCollection, PACK_SOURCE_LABEL, StickerCollectors, StickerPack, StickerPackSource, StickerStatusService, packDetail,
} from '../../core/sticker-status.service';
import { StickerCardData, requestTiltPermission } from '../sticker-card/sticker-card.component';
import { AlbumClub, Sticker } from './album.model';
import { AlbumSlot } from './album-club-page.component';
import { PackCard, packInfo, packRules } from './pack.model';
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

  // ── Wessen Album? (?manager=<id>; ohne bzw. eigene ID = eigenes Album) ─────
  readonly myId = this.auth.getManagerId();
  private managerParam = toSignal(this.route.queryParamMap.pipe(map(p => p.get('manager'))), { initialValue: null });
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

  /** Sammler-Rangliste — neu geladen, sobald sich die eigene Sammlung ändert (Pack geöffnet) */
  collectors = toSignal(
    toObservable(this.status.state).pipe(
      switchMap(() => this.api.get<StickerCollectors>('sticker/collectors').pipe(catchError(() => of(null)))),
    ),
    { initialValue: null },
  );

  /** Album eines Managers anzeigen (eigene ID / null = eigenes); die aktuelle Seite bleibt erhalten. */
  viewManager(id: string | null): void {
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { manager: id && id !== this.myId ? id : null },
      queryParamsHandling: 'merge',
    });
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
    requestTiltPermission(); // synchron in der Klick-Geste (iOS), falls danach eine Karte groß geöffnet wird
    this.opener.show(packInfo(pack));
  }

  /** Ein bestimmtes Pack aus der Detail-Liste zeigen. */
  openSpecificPack(pack: StickerPack): void {
    requestTiltPermission();
    this.opener.show(packInfo(pack));
  }

  openPulled(c: PackCard): void {
    requestTiltPermission();
    this.openCard.set(c.card);
  }

  // ── Admin: Album einfrieren/ergänzen + Test-Packs ─────────────────────────
  syncBusy = signal(false);
  syncResult = signal<string | null>(null);

  readonly testSources: { source: StickerPackSource; label: string }[] = [
    { source: 'daily', label: 'Tages-Pack' },
    { source: 'milestone', label: 'Meilenstein' },
    { source: 'matchday_best', label: 'Spieltagssieger' },
  ];

  /**
   * Test-Pack zum Ausprobieren von Pack-Optik und Öffnen-Animation: Größe + Garantie wie die echte Pack-Art,
   * die Sticker werden erst beim Aufreißen und nur im Browser gewürfelt — nichts wird gespeichert.
   */
  openTestPack(source: StickerPackSource): void {
    requestTiltPermission(); // synchron in der Klick-Geste (iOS)
    this.opener.show({
      id: null, source, size: packRules(source).size,
      milestonePoints: source === 'milestone' ? 100 * (1 + Math.floor(Math.random() * 15)) : null,
      matchdayNumber: source === 'matchday_best' ? 1 + Math.floor(Math.random() * 34) : null,
      leagueName: source === 'daily' ? null : 'Test-Liga',
    });
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
      queryParamsHandling: 'merge', // ?manager bleibt erhalten
      replaceUrl: true,
    });
    window.scrollTo({ top: 0 });
  }

  prev(): void { if (this.pageIndex() > 0) this.goTo(this.pageIndex() - 1); }
  next(): void { if (this.pageIndex() < this.pageCount() - 1) this.goTo(this.pageIndex() + 1); }

  /** Desktop: mit den Pfeiltasten blättern (nicht bei offenem Dialog / in Eingabefeldern). */
  @HostListener('document:keydown', ['$event'])
  onKey(e: KeyboardEvent): void {
    if (this.openCard() || this.opener.info() || e.altKey || e.ctrlKey || e.metaKey) return;
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
