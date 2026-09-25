import { Injectable, computed, inject, signal } from '@angular/core';
import { OpenedPack, StickerStatusService } from '../../core/sticker-status.service';
import { PackCard, PackInfo, packInfo, packRules } from './pack.model';
import { StickerAlbumService } from './sticker-album.service';

/**
 * Ablauf eines Pack-Dialogs (Sammelalbum + globale Ankündigung neuer Packs): welches Pack gezeigt wird,
 * Öffnen beim Aufreißen (Server bzw. Test-Pack im Browser), aufgedeckte Karten, nächstes Pack.
 * Je Seite zusammen mit StickerAlbumService bereitgestellt (providers).
 */
@Injectable()
export class PackOpener {
  private album = inject(StickerAlbumService);
  private status = inject(StickerStatusService);

  readonly info = signal<PackInfo | null>(null);
  readonly error = signal<string | null>(null);
  readonly busy = signal(false);
  private result = signal<{ opened?: OpenedPack; test?: PackCard[]; before: Uint16Array } | null>(null);
  /** in dieser Sitzung geöffnete Packs — bis GET /sticker/me aktualisiert ist, stehen sie dort noch drin */
  private openedIds = new Set<string>();

  /** Aufgedeckte Karten; null bis geöffnet (und das Album für die Kartendaten geladen) ist. */
  readonly cards = computed<PackCard[] | null>(() => {
    const r = this.result();
    if (!r) return null;
    if (r.test) return r.test;
    if (this.album.loading() || !r.opened) return null;
    return this.album.packCards(r.opened, r.before);
  });

  private unopened = computed(() => this.status.packs().filter(p => !this.openedIds.has(p.id) && p.id !== this.info()?.id));
  /** weitere ungeöffnete Packs (Test-Packs: keine) */
  readonly remaining = computed(() => (this.info()?.id ? this.unopened().length : 0));

  show(info: PackInfo): void {
    this.info.set(info);
    this.result.set(null);
    this.error.set(null);
  }

  showNext(): void {
    const next = this.unopened()[0];
    if (next) this.show(packInfo(next)); else this.close();
  }

  close(): void {
    this.info.set(null);
    this.result.set(null);
  }

  /** Beim Aufreißen: jetzt erst öffnen (die Animation läuft währenddessen). */
  tear(): void {
    const info = this.info();
    if (!info || this.result() || this.busy()) return;
    const before = this.album.collectionFrom(this.status.state()?.collection ?? []).counts;
    if (info.id === null) {
      this.result.set({ test: this.album.randomPackCards(info.size, before, packRules(info.source).allNew), before });
      return;
    }
    const id = info.id;
    this.busy.set(true);
    this.status.openPack(id).subscribe({
      next: opened => {
        this.busy.set(false);
        this.openedIds.add(id);
        if (this.info()?.id === id) this.result.set({ opened, before });
      },
      error: err => {
        this.busy.set(false);
        if (this.info()?.id === id) this.error.set(err?.error?.message ?? 'Pack konnte nicht geöffnet werden');
      },
    });
  }
}
