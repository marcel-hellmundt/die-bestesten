import { Component, inject, signal, computed, effect } from '@angular/core';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { switchMap, of, Subject, startWith } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

interface Offer {
  id: string;
  player_id: string;
  transferwindow_id: string;
  offer_value: number;
  price_snapshot: number;
  status: 'pending' | 'success' | 'lost' | 'cancelled';
  created_at: string;
  displayname: string | null;
  season_id: string | null;
  photo_uploaded: boolean;
  club_id: string | null;
  club_logo_uploaded: boolean;
}

@Component({
  selector: 'app-bids',
  standalone: false,
  templateUrl: './bids.component.html',
  styleUrl: './bids.component.scss',
})
export class BidsComponent {
  private api   = inject(ApiService);
  private cache = inject(DataCacheService);

  private refresh$ = new Subject<void>();

  private loadingSignal = signal(true);
  loading = computed(() => this.loadingSignal());
  error   = signal<string | null>(null);

  private team = toSignal(
    toObservable(this.cache.myTeamId).pipe(
      switchMap(id => id
        ? this.api.get<{ id: string; team_name: string; season_id: string }>('team/mine')
        : of(null)
      )
    )
  );

  private offersData = toSignal(
    toObservable(this.team).pipe(
      switchMap(t => {
        if (!t) return of({ offers: [] as Offer[], pending_sum: 0 });
        return this.refresh$.pipe(
          startWith(null),
          switchMap(() =>
            this.api.get<{ offers: Offer[]; pending_sum: number }>(`offer?team_id=${t.id}`)
          )
        );
      })
    )
  );

  allOffers = computed(() => this.offersData()?.offers ?? []);
  pendingSum        = computed(() => this.offersData()?.pending_sum ?? 0);
  teamId            = computed(() => this.team()?.id ?? null);

  activeFilter = signal<'pending' | 'success' | 'lost' | null>(null);

  offers = computed(() => {
    const f = this.activeFilter();
    const all = this.allOffers();
    return f ? all.filter(o => o.status === f) : all;
  });

  toggleFilter(status: 'pending' | 'success' | 'lost'): void {
    this.activeFilter.set(this.activeFilter() === status ? null : status);
  }

  // Edit state
  editingId = signal<string | null>(null);
  editPct   = signal(100);
  editBusy  = signal(false);
  editError = signal<string | null>(null);

  editValue = computed(() => {
    const offer = this.offers().find(o => o.id === this.editingId());
    if (!offer) return 0;
    return Math.round(offer.price_snapshot * this.editPct() / 100 / 10_000) * 10_000;
  });

  constructor() {
    this.cache.ensureMyTeam();
    effect(() => {
      if (this.offersData() !== undefined) this.loadingSignal.set(false);
    });
  }

  startEdit(offer: Offer): void {
    const pct = Math.round(offer.offer_value / offer.price_snapshot * 100);
    this.editPct.set(Math.min(200, Math.max(100, pct)));
    this.editError.set(null);
    this.editingId.set(offer.id);
  }

  cancelEdit(): void {
    this.editingId.set(null);
    this.editError.set(null);
  }

  submitEdit(): void {
    const teamId  = this.teamId();
    const offerId = this.editingId();
    if (!teamId || !offerId) return;
    this.editBusy.set(true);
    this.editError.set(null);
    this.api.patch<any>(`offer/${offerId}`, { team_id: teamId, offer_value: this.editValue() }).subscribe({
      next: () => {
        this.editBusy.set(false);
        this.editingId.set(null);
        this.refresh$.next();
      },
      error: (err: any) => {
        this.editBusy.set(false);
        this.editError.set(err?.error?.message ?? 'Fehler beim Speichern');
      },
    });
  }

  cancelOffer(offer: Offer): void {
    const teamId = this.teamId();
    if (!teamId) return;
    this.api.delete<any>(`offer/${offer.id}`, { team_id: teamId }).subscribe({
      next: () => this.refresh$.next(),
      error: () => {},
    });
  }

  photoErrors = new Set<string>();
  onPhotoError(playerId: string): void { this.photoErrors.add(playerId); }

  photoUrl(offer: Offer): string | null {
    if (!offer.photo_uploaded || !offer.season_id) return null;
    return `https://img.die-bestesten.de/img/player/${offer.season_id}/${offer.player_id}.png`;
  }

  clubLogoUrl(offer: Offer): string | null {
    if (!offer.club_id || !offer.club_logo_uploaded) return null;
    return `https://img.die-bestesten.de/img/club/${offer.club_id}.png`;
  }

  bidPctClass(offer: Offer): string {
    const pct = offer.offer_value / offer.price_snapshot * 100;
    if (pct >= 200) return 'bid-pct--danger';
    if (pct > 100)  return 'bid-pct--warning';
    return 'bid-pct--success';
  }

  statusLabel(status: string): string {
    return ({ pending: 'Ausstehend', success: 'Gewonnen', lost: 'Verloren', cancelled: 'Storniert' } as Record<string, string>)[status] ?? status;
  }

  formatPrice(v: number): string {
    return v.toLocaleString('de-DE') + ' €';
  }

  bidPct(offer: Offer): string {
    return Math.round(offer.offer_value / offer.price_snapshot * 100) + '%';
  }
}
