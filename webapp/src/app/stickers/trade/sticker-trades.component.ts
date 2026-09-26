import { Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import {
  OtherCollection, StickerCollectors, StickerStatusService, StickerTrade, StickerTrades,
} from '../../core/sticker-status.service';
import { ALBUM_SOURCE, Collection, StickerAlbumService } from '../album/sticker-album.service';
import { Sticker } from '../album/album.model';

/**
 * Tauschbörse (/klebrigsten/tausch): offene Tauschangebote an mich (annehmen/ablehnen), meine offenen
 * (zurückziehen), Verlauf — und Tauschpartner: wer hat Doppelte, die mir fehlen, und braucht meine?
 */
@Component({
  selector: 'app-sticker-trades',
  standalone: false,
  templateUrl: './sticker-trades.component.html',
  styleUrl: './sticker-trades.component.scss',
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album' }, StickerAlbumService],
})
export class StickerTradesComponent {
  private api = inject(ApiService);
  private album = inject(StickerAlbumService);
  private cache = inject(DataCacheService);
  status = inject(StickerStatusService);

  private reloadTick = signal(0);
  private reload(): void { this.reloadTick.update(n => n + 1); }

  private data = toSignal(toObservable(this.reloadTick).pipe(
    switchMap(() => this.status.trades().pipe(catchError(() => of(null)))),
  ));
  loading = computed(() => this.data() === undefined);
  trades = computed<StickerTrades | null>(() => this.data() ?? null);

  // neu geladen, sobald sich die eigene Sammlung ändert (Tausch angenommen, Pack geöffnet)
  private collectors = toSignal(toObservable(this.status.state).pipe(
    switchMap(() => this.api.get<StickerCollectors>('sticker/collectors').pipe(catchError(() => of(null)))),
  ));
  /** Tauschpartner: beide Seiten haben etwas füreinander — die besten Treffer zuerst. */
  partners = computed(() => (this.collectors()?.collectors ?? [])
    .filter(c => (c.trade_get ?? 0) > 0 && (c.trade_give ?? 0) > 0)
    .sort((a, b) => Math.min(b.trade_get!, b.trade_give!) - Math.min(a.trade_get!, a.trade_give!)));

  private byKey = computed(() => new Map(this.album.stickers().map(s => [s.id, s])));
  stickersOf(keys: string[]): Sticker[] {
    const m = this.byKey();
    return keys.flatMap(k => { const s = m.get(k); return s ? [s] : []; });
  }

  photoUrl(managerId: string): string | null { return this.cache.managerPhotoUrl(managerId); }
  photoFailed = signal(new Set<string>());
  onPhotoError(id: string): void { this.photoFailed.update(s => new Set(s).add(id)); }
  initials(name: string): string { return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase(); }

  readonly statusLabel: Record<string, string> = {
    pending: 'offen', accepted: 'getauscht', declined: 'abgelehnt', cancelled: 'zurückgezogen', void: 'hinfällig',
  };

  // ── Aktionen ──
  busyId = signal<string | null>(null);
  message = signal<string | null>(null);

  respond(t: StickerTrade, action: 'accept' | 'decline'): void {
    this.run(t, this.status.respondTrade(t.id, action), action === 'accept' ? 'Getauscht! Die Sticker sind jetzt in deinem Album.' : null);
  }

  cancel(t: StickerTrade): void {
    this.run(t, this.status.cancelTrade(t.id), null);
  }

  private run(t: StickerTrade, req: ReturnType<StickerStatusService['cancelTrade']>, success: string | null): void {
    this.busyId.set(t.id);
    this.message.set(null);
    req.subscribe({
      next: () => { this.busyId.set(null); this.message.set(success); this.reload(); },
      error: err => { this.busyId.set(null); this.message.set(err?.error?.message ?? 'Das hat nicht geklappt'); this.reload(); },
    });
  }

  // ── Neues Angebot direkt von hier ──
  dialog = signal<{ id: string; name: string; collection: Collection } | null>(null);
  openingId = signal<string | null>(null);

  startTrade(managerId: string, name: string): void {
    this.openingId.set(managerId);
    this.api.get<OtherCollection>(`sticker/collection/${managerId}`).subscribe({
      next: c => {
        this.openingId.set(null);
        this.dialog.set({ id: managerId, name, collection: this.album.collectionFrom(c.collection) });
      },
      error: () => this.openingId.set(null),
    });
  }

  closeDialog(): void {
    this.dialog.set(null);
    this.reload();
  }
}
