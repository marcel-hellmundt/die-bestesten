import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

interface Transferwindow {
  id: string;
  matchday_id: string;
  start_date: string;
  end_date: string;
}

interface Bid {
  id: string;
  team_id: string;
  team_name: string | null;
  team_color: string | null;
  team_season_id: string | null;
  offer_value: number;
  price_snapshot: number | null;
  status: 'success' | 'lost' | 'cancelled' | 'pending';
  created_at: string;
}

interface PlayerOffers {
  player_id: string;
  season_id: string | null;
  displayname: string | null;
  position: 'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD' | null;
  photo_uploaded: boolean;
  club_id: string | null;
  club_logo_uploaded: boolean;
  bids: Bid[];
}

interface WindowOffersResponse {
  window: Transferwindow;
  offers: PlayerOffers[];
}

@Component({
  selector: 'app-transfer-window-detail',
  standalone: false,
  templateUrl: './transfer-window-detail.component.html',
  styleUrl: './transfer-window-detail.component.scss',
})
export class TransferWindowDetailComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);

  private response = toSignal(
    this.route.paramMap.pipe(
      map(p => p.get('id')),
      switchMap(id => id
        ? this.api.get<WindowOffersResponse>(`offer?transferwindow_id=${id}`).pipe(
            catchError(() => of(null))
          )
        : of(null)
      )
    ),
    { initialValue: null as WindowOffersResponse | null }
  );

  window  = computed(() => this.response()?.window ?? null);
  loading = computed(() => this.response() === null);

  offers = computed(() =>
    [...(this.response()?.offers ?? [])].sort((a, b) => {
      const maxA = Math.max(...a.bids.map(bid => bid.offer_value), 0);
      const maxB = Math.max(...b.bids.map(bid => bid.offer_value), 0);
      return maxB - maxA;
    })
  );

  readonly positionColors: Record<string, string> = {
    GOALKEEPER: 'var(--position-goalkeeper)',
    DEFENDER:   'var(--position-defender)',
    MIDFIELDER: 'var(--position-midfielder)',
    FORWARD:    'var(--position-forward)',
  };

  readonly positionLabel: Record<string, string> = {
    GOALKEEPER: 'TOR',
    DEFENDER:   'ABW',
    MIDFIELDER: 'MIT',
    FORWARD:    'STU',
  };

  logoErrors = new Set<string>();
  onLogoError(teamId: string): void { this.logoErrors.add(teamId); }

  teamLogoUrl(bid: Bid): string {
    return `https://img.die-bestesten.de/img/team/${bid.team_season_id}/${bid.team_id}.png`;
  }

  photoUrl(entry: PlayerOffers): string | null {
    if (!entry.photo_uploaded || !entry.season_id) return null;
    return `https://img.die-bestesten.de/img/player/${entry.season_id}/${entry.player_id}.png`;
  }

  clubLogoUrl(clubId: string | null, uploaded: boolean): string {
    if (!clubId || !uploaded) return 'img/placeholders/club.png';
    return `https://img.die-bestesten.de/img/club/${clubId}.png`;
  }

  winner(entry: PlayerOffers): Bid | undefined {
    return entry.bids.find(b => b.status === 'success');
  }

  losers(entry: PlayerOffers): Bid[] {
    return entry.bids
      .filter(b => b.status === 'lost' || b.status === 'cancelled')
      .sort((a, b) => b.offer_value - a.offer_value);
  }

  bidPct(offerValue: number, priceSnapshot: number | null): string | null {
    if (!priceSnapshot) return null;
    return Math.round(offerValue / priceSnapshot * 100) + '%';
  }

  bidPctClass(offerValue: number, priceSnapshot: number | null): string {
    if (!priceSnapshot) return '';
    const pct = offerValue / priceSnapshot * 100;
    if (pct >= 200) return 'winner-bid__pct--danger';
    if (pct > 100)  return 'winner-bid__pct--warning';
    return 'winner-bid__pct--success';
  }

  formatPrice(value: number): string {
    return value.toLocaleString('de-DE') + ' €';
  }
}
