import { Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { PACK_SOURCE_LABEL, StickerCollectors, StickerPackSource, StickerStatusService } from '../../core/sticker-status.service';
import { PACK_KINDS, PackKind } from '../shop/shop.model';

/**
 * "Klebebande" (/klebrigsten/klebebande): Rangliste aller Sammler der Saison — Klick öffnet das
 * jeweilige Album zum Ansehen (/klebrigsten/klebebande/:managerId), das eigene führt ins Sammelalbum.
 */
@Component({
  selector: 'app-sticker-collectors',
  standalone: false,
  templateUrl: './sticker-collectors.component.html',
  styleUrl: './sticker-collectors.component.scss',
})
export class StickerCollectorsComponent {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private cache = inject(DataCacheService);
  private status = inject(StickerStatusService);

  readonly myId = this.auth.getManagerId();
  /** Admins sehen links neben dem Bild, wie viele ungeöffnete Packs jeder Manager hat */
  readonly isAdmin = this.auth.isAdmin();

  // neu geladen, sobald sich die eigene Sammlung ändert (Pack geöffnet); bis dahin bleibt die alte Liste stehen
  private data = toSignal(
    toObservable(this.status.state).pipe(
      switchMap(() => this.api.get<StickerCollectors>('sticker/collectors').pipe(catchError(() => of(null)))),
    ),
  );
  loading = computed(() => this.data() === undefined);
  total = computed(() => this.data()?.total ?? 0);

  /** Rangliste mit Platz (gleiche Anzahl = gleicher Platz) */
  ranking = computed(() => {
    const c = this.data();
    if (!c || c.total === 0) return [];
    let lastHave = -1, lastRank = 0;
    return c.collectors.map((m, i) => {
      const rank = m.have === lastHave ? lastRank : i + 1;
      lastHave = m.have; lastRank = rank;
      return {
        ...m, rank, pct: m.have / c.total,
        photo: this.cache.managerPhotoUrl(m.manager_id),
        initials: m.manager_name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase(),
        link: m.manager_id === this.myId ? ['/klebrigsten/sammelalbum'] : ['/klebrigsten/klebebande', m.manager_id],
      };
    });
  });

  /** Anzeigename einer Pack-Art für den Tooltip: Shop-Packs nach Art (Big Pack …), sonst nach Quelle (Tages-Pack …) */
  packTypeLabel(type: string): string {
    return PACK_KINDS[type as PackKind]?.name ?? PACK_SOURCE_LABEL[type as StickerPackSource] ?? type;
  }

  /** Admin-Chips rechts: feste Reihenfolge (Event-Packs, dann Shop-Packs), Farbe wie das jeweilige Pack-Design */
  private readonly PACK_TYPE_ORDER = ['daily', 'milestone', 'matchday_best', 'normal', 'big', 'club', 'special', 'shop', 'admin'];
  private readonly PACK_TYPE_COLOR: Record<string, string> = {
    daily: '#bf1d00', milestone: '#4b7bec', matchday_best: '#fed330', shop: '#0f766e', admin: '#4b5563',
    normal: '#6aba49', big: '#f26d53', club: '#fcc732', special: '#8854d0',
  };
  /** Kurzname im Chip (voller Name im Tooltip) */
  private readonly PACK_TYPE_SHORT: Record<string, string> = {
    daily: 'Tages', milestone: 'Meilenstein', matchday_best: 'Sieger', shop: 'Shop', admin: 'Admin',
    normal: 'Normal', big: 'Big', club: 'Verein', special: 'Special',
  };
  /** helle Pack-Farben (Gelb/Grün) brauchen dunkle Schrift */
  private readonly DARK_TEXT = new Set(['matchday_best', 'normal', 'club']);

  packChips(packs: { type: string; total: number; opened: number }[] | undefined) {
    const rank = (t: string) => { const i = this.PACK_TYPE_ORDER.indexOf(t); return i < 0 ? 99 : i; };
    return [...(packs ?? [])]
      .sort((a, b) => rank(a.type) - rank(b.type))
      .map(p => ({
        ...p,
        label: this.packTypeLabel(p.type),
        short: this.PACK_TYPE_SHORT[p.type] ?? p.type,
        color: this.PACK_TYPE_COLOR[p.type] ?? '#6b7280',
        ink: this.DARK_TEXT.has(p.type) ? '#1f2937' : '#fff',
      }));
  }

  /** Manager, deren Foto nicht geladen werden konnte → Initialen */
  photoFailed = signal(new Set<string>());
  onPhotoError(id: string): void {
    this.photoFailed.update(s => new Set(s).add(id));
  }
}
