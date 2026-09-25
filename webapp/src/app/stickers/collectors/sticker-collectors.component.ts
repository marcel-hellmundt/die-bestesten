import { Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { StickerCollectors, StickerStatusService } from '../../core/sticker-status.service';

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

  /** Manager, deren Foto nicht geladen werden konnte → Initialen */
  photoFailed = signal(new Set<string>());
  onPhotoError(id: string): void {
    this.photoFailed.update(s => new Set(s).add(id));
  }
}
