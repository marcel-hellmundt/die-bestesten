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
  position: string | null;
  season_id: string | null;
  photo_uploaded: boolean;
  club_id: string | null;
  club_logo_uploaded: boolean;
  losers: { team_id: string; team_color: string | null; team_season_id: string | null; is_winner: boolean }[];
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

  allOffers = computed(() => (this.offersData()?.offers ?? []).filter(o => o.status !== 'cancelled'));
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
  editBusy  = signal(false);
  editError = signal<string | null>(null);

  editingOffer = computed(() => this.allOffers().find(o => o.id === this.editingId()) ?? null);

  // Digit spinner — 4 controllable digits (10M / 1M / 100K / 10K), granularity 10.000,
  // same input pattern as the "Gebot abgeben" dialog (player-detail.component).
  digitE10000000 = signal(0);
  digitE1000000  = signal(0);
  digitE100000   = signal(0);
  digitE10000    = signal(0);

  editValue = computed(() =>
    this.digitE10000000() * 10_000_000 +
    this.digitE1000000()  *  1_000_000 +
    this.digitE100000()   *    100_000 +
    this.digitE10000()    *     10_000
  );

  editPercentage = computed(() => {
    const snapshot = this.editingOffer()?.price_snapshot;
    if (!snapshot) return 0;
    return Math.round(this.editValue() / snapshot * 100);
  });

  sliderMin = computed(() => {
    const snapshot = this.editingOffer()?.price_snapshot ?? 0;
    return Math.ceil(snapshot / 10_000) * 10_000;
  });
  sliderMax = computed(() => this.sliderMin() * 2);

  constructor() {
    this.cache.ensureMyTeam();
    effect(() => {
      if (this.offersData() !== undefined) this.loadingSignal.set(false);
    });
  }

  private setDigitsFromValue(v: number): void {
    const s = String(Math.max(0, Math.floor(v / 10_000) * 10_000)).padStart(8, '0');
    this.digitE10000000.set(+s[s.length - 8] || 0);
    this.digitE1000000.set( +s[s.length - 7] || 0);
    this.digitE100000.set(  +s[s.length - 6] || 0);
    this.digitE10000.set(   +s[s.length - 5] || 0);
  }

  updateDigit(prop: 'digitE10000000' | 'digitE1000000' | 'digitE100000' | 'digitE10000', delta: number): void {
    const sigs: Record<string, ReturnType<typeof signal<number>>> = {
      digitE10000000: this.digitE10000000,
      digitE1000000:  this.digitE1000000,
      digitE100000:   this.digitE100000,
      digitE10000:    this.digitE10000,
    };
    sigs[prop].update(v => v + delta);

    // Carry-over logic
    if (this.digitE10000() > 9)  { this.digitE100000.update(v => v + 1);   this.digitE10000.set(0); }
    if (this.digitE10000() < 0)  { this.digitE10000.set(0); }
    if (this.digitE100000() > 9) { this.digitE1000000.update(v => v + 1);  this.digitE100000.set(0); }
    if (this.digitE100000() < 0) { this.digitE100000.set(0); }
    if (this.digitE1000000() > 9){ this.digitE10000000.update(v => v + 1); this.digitE1000000.set(0); }
    if (this.digitE1000000() < 0){ this.digitE1000000.set(0); }
    if (this.digitE10000000() > 9) { this.digitE10000000.set(9); }
    if (this.digitE10000000() < 0) { this.digitE10000000.set(0); }
  }

  onSliderInput(value: number): void {
    this.setDigitsFromValue(value);
  }

  startEdit(offer: Offer): void {
    this.setDigitsFromValue(offer.offer_value);
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

  loserErrors = new Set<string>();
  onLoserError(teamId: string): void { this.loserErrors.add(teamId); }

  loserLogoUrl(loser: { team_id: string; team_season_id: string | null }): string {
    return `https://img.die-bestesten.de/team/${loser.team_season_id}/${loser.team_id}.png`;
  }

  sortedLosers(offer: Offer): Offer['losers'] {
    return [...offer.losers].sort((a, b) => (b.is_winner ? 1 : 0) - (a.is_winner ? 1 : 0));
  }

  photoUrl(offer: Offer): string | null {
    if (!offer.photo_uploaded || !offer.season_id) return null;
    return `https://img.die-bestesten.de/player/${offer.season_id}/${offer.player_id}.png`;
  }

  clubLogoUrl(offer: Offer): string | null {
    if (!offer.club_id || !offer.club_logo_uploaded) return null;
    return `https://img.die-bestesten.de/club/${offer.club_id}.png`;
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

  private static readonly POS_LABEL: Record<string, string> = {
    GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU',
  };

  posLabel(pos: string | null): string {
    return pos ? (BidsComponent.POS_LABEL[pos] ?? pos) : '';
  }
}
