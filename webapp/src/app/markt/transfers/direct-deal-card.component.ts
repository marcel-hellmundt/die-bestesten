import { Component, input } from '@angular/core';

// Vollzogenes Direktangebot ("Hinterzimmerdeal", siehe /player_offer) — nur angenommene Deals eines Fensters.
export interface DealTeam { team_id: string; team_name: string; color: string | null; season_id: string; manager_name: string; }
export interface DealOfferedPlayer { player_id: string; displayname: string | null; position: string | null; photo_uploaded: boolean; }
export interface DirectDeal {
  id: string;
  player_id: string;
  season_id: string | null;
  displayname: string | null;
  position: 'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD' | null;
  photo_uploaded: boolean;
  club_id: string | null;
  club_logo_uploaded: boolean;
  seller: DealTeam | null;
  buyer: DealTeam | null;
  offered_players?: DealOfferedPlayer[]; // Spieler als Gegenwert
  price: number;
  price_snapshot: number;
  accepted_at: string | null;
}

/** Links Bieter (Team + Geld/Spieler), Mitte Tausch-Pfeil, rechts gehandelter Spieler + abgebendes Team. */
@Component({
  selector: 'app-direct-deal-card',
  standalone: false,
  templateUrl: './direct-deal-card.component.html',
  styleUrl: './direct-deal-card.component.scss',
})
export class DirectDealCardComponent {
  deal = input.required<DirectDeal>();

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

  teamLogoUrl(team: DealTeam): string {
    return `https://img.die-bestesten.de/team/${team.season_id}/${team.team_id}.png`;
  }

  playerPhotoUrl(playerId: string, uploaded: boolean): string | null {
    const seasonId = this.deal().season_id;
    if (!uploaded || !seasonId) return null;
    return `https://img.die-bestesten.de/player/${seasonId}/${playerId}.png`;
  }

  /** Reiner Spielertausch ohne Geld → "Tausch" über dem Pfeil statt eines 0-€-Betrags. */
  isPureSwap(): boolean {
    const d = this.deal();
    return d.price === 0 && !!d.offered_players?.length;
  }

  formatPrice(value: number): string {
    return value.toLocaleString('de-DE') + ' €';
  }
}
