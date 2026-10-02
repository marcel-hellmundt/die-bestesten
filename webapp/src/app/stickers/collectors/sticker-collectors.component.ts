import { Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, scan, switchMap, timer } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { PACK_SOURCE_LABEL, StickerCollectors, StickerPackSource, StickerStatusService } from '../../core/sticker-status.service';
import { PACK_KINDS, PackKind } from '../shop/shop.model';
import { packRules } from '../album/pack.model';

/** Admins: Liste (Pack-Übersicht) im Hintergrund neu laden — wie die Heatmap auf /verwaltung/nutzung */
const ADMIN_REFRESH_MS = 15_000;

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

  // neu geladen, sobald sich die eigene Sammlung ändert (Pack geöffnet), für Admins zusätzlich alle
  // ADMIN_REFRESH_MS; bis dahin — und wenn ein Abruf scheitert — bleibt die alte Liste stehen
  private data = toSignal(
    toObservable(this.status.state).pipe(
      switchMap(() => this.isAdmin ? timer(0, ADMIN_REFRESH_MS) : of(0)),
      switchMap(() => this.api.get<StickerCollectors>('sticker/collectors').pipe(catchError(() => of(null)))),
      scan((prev, cur) => cur ?? prev, null as StickerCollectors | null),
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

  /** Admin-Übersicht rechts: feste Reihenfolge (Event-Packs, dann Shop-Packs) und ein Symbol je Pack-Art */
  private readonly PACK_TYPE_ORDER = ['daily', 'milestone', 'matchday_best', 'streak', 'birthday', 'christmas', 'normal', 'big', 'club', 'special', 'shop', 'admin'];
  private readonly PACK_TYPE_ICON: Record<string, string> = {
    daily: '📅', milestone: '📈', matchday_best: '🏅', streak: '🔥', birthday: '🎂', christmas: '🎄',
    normal: '🦖', big: '🐉', club: '🛡️', special: '🧙', shop: '🛒', admin: '🎁',
  };

  /** Hintergrund der Zelle: Farbe wie das jeweilige Pack-Design */
  private readonly PACK_TYPE_COLOR: Record<string, string> = {
    daily: '#bf1d00', milestone: '#4b7bec', matchday_best: '#fed330', shop: '#0f766e', admin: '#4b5563',
    birthday: '#e84393', christmas: '#1e8449', streak: '#fa8231',
    normal: '#6aba49', big: '#f26d53', club: '#fcc732', special: '#8854d0',
  };
  /** helle Pack-Farben (Gelb/Grün) brauchen dunkle Schrift */
  private readonly DARK_TEXT = new Set(['matchday_best', 'normal', 'club']);

  /** Karten je Pack einer Art — null, wenn die Art keine feste Größe hat (alte Shop-Packs ohne Art, Admin-Packs) */
  private packSize(type: string): number | null {
    if (type in PACK_KINDS) return PACK_KINDS[type as PackKind].size;
    return type === 'shop' || type === 'admin' ? null : packRules(type as StickerPackSource).size;
  }

  /**
   * Spalten der Admin-Übersicht: alle Pack-Arten, die irgendein Manager bekommen hat — für jeden Manager dieselben,
   * damit gleiche Arten untereinander stehen (wer keine hat: Zelle bleibt leer, nichts rückt nach).
   */
  packTypes = computed(() => {
    const seen = new Set<string>();
    for (const m of this.data()?.collectors ?? []) for (const p of m.packs ?? []) seen.add(p.type);
    const rank = (t: string) => { const i = this.PACK_TYPE_ORDER.indexOf(t); return i < 0 ? 99 : i; };
    return [...seen].sort((a, b) => rank(a) - rank(b));
  });

  /** je Spalte (packTypes) die Zelle eines Managers — null = keine Packs dieser Art */
  packCells(packs: { type: string; total: number; opened: number; cards?: number }[] | undefined) {
    return this.packTypes().map((type, i, all) => {
      const p = packs?.find(x => x.type === type);
      if (!p) return null;
      return {
        ...p,
        label: this.packTypeLabel(type),
        icon: this.PACK_TYPE_ICON[type] ?? '📦',
        size: this.packSize(type),
        color: this.PACK_TYPE_COLOR[type] ?? '#6b7280',
        ink: this.DARK_TEXT.has(type) ? '#1f2937' : '#fff',
        unopened: p.total - p.opened,
        tipRight: i >= all.length / 2,   // rechte Hälfte: Tooltip rechtsbündig, sonst ragt er aus der Liste
      };
    });
  }

  /** Manager, deren Foto nicht geladen werden konnte → Initialen */
  photoFailed = signal(new Set<string>());
  onPhotoError(id: string): void {
    this.photoFailed.update(s => new Set(s).add(id));
  }
}
