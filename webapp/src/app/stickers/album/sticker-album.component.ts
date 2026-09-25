import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { map } from 'rxjs';
import { AuthService } from '../../auth/auth.service';
import { StickerCardData, requestTiltPermission } from '../sticker-card/sticker-card.component';
import { AlbumClub, Sticker } from './album.model';
import { AlbumSlot } from './album-club-page.component';
import { StickerAlbumService } from './sticker-album.service';
import { seasonTheme } from './season-theme';

type DemoMode = 'today' | 'end';

/**
 * Sammelalbum (/klebrigsten/sammelalbum): Seite 0 = Übersicht, danach eine Seite je Verein
 * (Vorsaison-Reihenfolge). Aktuelle Seite als ?seite=<Kurzname> in der URL.
 * Bis es echte Packs gibt, zeigt das Album eine Demo-Sammlung (simulierte Saison je Manager).
 */
@Component({
  selector: 'app-sticker-album',
  standalone: false,
  templateUrl: './sticker-album.component.html',
  styleUrl: './sticker-album.component.scss',
})
export class StickerAlbumComponent {
  private album = inject(StickerAlbumService);
  private auth = inject(AuthService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);

  loading = this.album.loading;
  error = this.album.error;
  rows = this.album.rows;

  demoMode = signal<DemoMode>('today');
  pickerOpen = signal(false);
  openCard = signal<StickerCardData | null>(null);

  theme = computed(() => seasonTheme(this.album.seasonId() ?? ''));

  collection = computed(() => {
    const day = this.demoMode() === 'end' ? this.album.timeline().days : this.album.todayDay();
    return this.album.demoCollection(this.auth.getManagerId() ?? 'guest', day);
  });

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
    if (this.openCard() || e.altKey || e.ctrlKey || e.metaKey) return;
    const el = e.target as HTMLElement | null;
    if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
    if (e.key === 'ArrowLeft') { this.prev(); e.preventDefault(); }
    else if (e.key === 'ArrowRight') { this.next(); e.preventDefault(); }
  }

  // ── Dialog ────────────────────────────────────────────────────────────────
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

  setDemoMode(mode: DemoMode): void {
    this.demoMode.set(mode);
  }
}
