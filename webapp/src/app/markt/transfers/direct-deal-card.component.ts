import { Component, input } from '@angular/core';

// Vollzogenes Direktangebot ("Hinterzimmerdeal", siehe /player_offer) — nur angenommene Deals eines Fensters.
export interface DealTeam { team_id: string; team_name: string; color: string | null; season_id: string; manager_name: string; }
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
  offered_players?: { player_id: string; displayname: string | null; position: string | null }[]; // Spieler als Gegenwert
  price: number;
  price_snapshot: number;
  accepted_at: string | null;
}

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

  /** Zusätzlich zum Geld getauschte Spieler, z. B. "Müller (MIT), Schmidt (ABW)". */
  playersText(): string {
    return (this.deal().offered_players ?? [])
      .map(p => `${p.displayname ?? '–'}${p.position ? ' (' + this.positionLabel[p.position] + ')' : ''}`)
      .join(', ');
  }

  teamLogoUrl(team: DealTeam): string {
    return `https://img.die-bestesten.de/team/${team.season_id}/${team.team_id}.png`;
  }

  photoUrl(): string | null {
    const d = this.deal();
    if (!d.photo_uploaded || !d.season_id) return null;
    return `https://img.die-bestesten.de/player/${d.season_id}/${d.player_id}.png`;
  }

  clubLogoUrl(): string {
    const d = this.deal();
    if (!d.club_id || !d.club_logo_uploaded) return 'img/placeholders/club.png';
    return `https://img.die-bestesten.de/club/${d.club_id}.png`;
  }

  pct(): string | null {
    const d = this.deal();
    if (!d.price_snapshot) return null;
    return Math.round(d.price / d.price_snapshot * 100) + '%';
  }

  pctClass(): string {
    const d = this.deal();
    if (!d.price_snapshot) return '';
    const pct = d.price / d.price_snapshot * 100;
    if (pct >= 200) return 'deal-price__pct--danger';
    if (pct > 100)  return 'deal-price__pct--warning';
    return 'deal-price__pct--success';
  }

  formatPrice(value: number): string {
    return value.toLocaleString('de-DE') + ' €';
  }
}
