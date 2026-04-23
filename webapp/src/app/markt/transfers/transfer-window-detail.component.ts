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
  displayname: string | null;
  position: 'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD' | null;
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
  offers  = computed(() => this.response()?.offers ?? []);
  loading = computed(() => this.response() === null);

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

  clubLogoUrl(clubId: string | null, uploaded: boolean): string {
    if (!clubId || !uploaded) return 'img/placeholders/club.png';
    return `https://img.die-bestesten.de/img/club/${clubId}.png`;
  }

  winner(entry: PlayerOffers): Bid | undefined {
    return entry.bids.find(b => b.status === 'success');
  }

  losers(entry: PlayerOffers): Bid[] {
    return entry.bids.filter(b => b.status === 'lost' || b.status === 'cancelled');
  }

  formatPrice(value: number): string {
    return value.toLocaleString('de-DE') + ' €';
  }
}
