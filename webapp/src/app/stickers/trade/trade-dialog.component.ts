import { Component, HostListener, computed, inject, input, output, signal } from '@angular/core';
import { StickerStatusService } from '../../core/sticker-status.service';
import { Collection, StickerAlbumService } from '../album/sticker-album.service';
import { Sticker, TIERS, TIER_LABEL, Tier } from '../album/album.model';

const MAX_ITEMS = 10; // wie TRADE_MAX_ITEMS im Backend

/**
 * Tauschangebot an einen anderen Manager: links seine Doppelten, die mir fehlen, rechts meine Doppelten,
 * die ihm fehlen — auf beiden Seiten mind. 1, höchstens 10 wählen. Seltenste zuerst.
 */
@Component({
  selector: 'app-trade-dialog',
  standalone: false,
  templateUrl: './trade-dialog.component.html',
  styleUrl: './trade-dialog.component.scss',
})
export class TradeDialogComponent {
  private album = inject(StickerAlbumService);
  private status = inject(StickerStatusService);

  partnerId = input.required<string>();
  partnerName = input.required<string>();
  theirs = input.required<Collection>();
  closed = output<void>();

  private mine = computed(() => this.album.collectionFrom(this.status.state()?.collection ?? []));

  /** seltenste (teuerste) zuerst */
  private sorted = computed(() => [...this.album.stickers()].sort((a, b) => (b.price ?? 0) - (a.price ?? 0)));
  canGet = computed(() => { const m = this.mine(), t = this.theirs(); return this.sorted().filter(s => t.counts[s.idx] >= 2 && !m.counts[s.idx]); });
  canGive = computed(() => { const m = this.mine(), t = this.theirs(); return this.sorted().filter(s => m.counts[s.idx] >= 2 && !t.counts[s.idx]); });

  get = signal(new Set<string>());
  give = signal(new Set<string>());
  readonly max = MAX_ITEMS;

  toggle(side: 'get' | 'give', s: Sticker): void {
    const sig = side === 'get' ? this.get : this.give;
    sig.update(set => {
      const next = new Set(set);
      if (next.has(s.id)) next.delete(s.id);
      else if (next.size < MAX_ITEMS) next.add(s.id);
      return next;
    });
  }

  /** z.B. "1 Legendär · 2 Selten" — damit man die Fairness auf einen Blick sieht. */
  summary(ids: Set<string>): string {
    const counts: Partial<Record<Tier, number>> = {};
    for (const s of this.album.stickers()) if (ids.has(s.id)) counts[s.tier] = (counts[s.tier] ?? 0) + 1;
    return [...TIERS].reverse().filter(t => counts[t]).map(t => `${counts[t]} ${TIER_LABEL[t]}`).join(' · ') || '–';
  }

  canSend = computed(() => this.get().size > 0 && this.give().size > 0 && !this.busy());
  busy = signal(false);
  error = signal<string | null>(null);
  sent = signal(false);

  send(): void {
    if (!this.canSend()) return;
    this.busy.set(true);
    this.error.set(null);
    this.status.offerTrade(this.partnerId(), [...this.give()], [...this.get()]).subscribe({
      next: () => { this.busy.set(false); this.sent.set(true); },
      error: err => { this.busy.set(false); this.error.set(err?.error?.message ?? 'Angebot konnte nicht verschickt werden'); },
    });
  }

  @HostListener('document:keydown.escape')
  close(): void { if (!this.busy()) this.closed.emit(); }
}
