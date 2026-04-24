import { Component, inject, signal, computed } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ApiService } from '../../core/api.service';

interface FreeAgent {
  id: string;
  displayname: string;
  position: string;
  price: number;
  season_points: number;
  photo_uploaded: boolean;
  club_id: string;
  club_name: string;
  club_short_name: string;
  club_logo_uploaded: boolean;
  season_id: string;
}

@Component({
  selector: 'app-markt-player',
  standalone: false,
  templateUrl: './markt-player.component.html',
  styleUrl: './markt-player.component.scss',
})
export class MarktPlayerComponent {
  private api = inject(ApiService);

  private data = toSignal(
    this.api.get<{ players: FreeAgent[] }>('player_in_season/available_players')
  );

  players = computed(() => this.data()?.players ?? []);
  loading = computed(() => this.data() === undefined);

  searchQuery    = signal('');
  positionFilter = signal<string | null>(null);
  clubFilter     = signal<string | null>(null);
  maxPrice       = signal<number | null>(null);

  maxDataPrice = computed(() => Math.max(0, ...this.players().map(p => p.price)));

  clubs = computed(() => {
    const seen = new Set<string>();
    return this.players()
      .filter(p => { if (seen.has(p.club_id)) return false; seen.add(p.club_id); return true; })
      .map(p => ({ id: p.club_id, name: p.club_name, short_name: p.club_short_name, logo_uploaded: p.club_logo_uploaded }))
      .sort((a, b) => a.name.localeCompare(b.name));
  });

  filteredPlayers = computed(() => {
    const q    = this.searchQuery().trim().toLowerCase();
    const pos  = this.positionFilter();
    const club = this.clubFilter();
    const max  = this.maxPrice();
    return this.players().filter(p =>
      (!q    || p.displayname.toLowerCase().includes(q)) &&
      (!pos  || p.position === pos) &&
      (!club || p.club_id === club) &&
      (max === null || p.price <= max)
    );
  });

  hasFilters = computed(() =>
    !!this.searchQuery() || !!this.positionFilter() || !!this.clubFilter() || this.maxPrice() !== null
  );

  readonly POSITIONS = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];
  readonly POS_LABEL: Record<string, string> = {
    GOALKEEPER: 'TOR', DEFENDER: 'ABW', MIDFIELDER: 'MIT', FORWARD: 'STU',
  };

  photoErrors = new Set<string>();
  clubErrors  = new Set<string>();
  onPhotoError(id: string): void { this.photoErrors.add(id); }
  onClubError(id: string): void  { this.clubErrors.add(id); }

  photoUrl(p: FreeAgent): string | null {
    if (!p.photo_uploaded) return null;
    return `https://img.die-bestesten.de/img/player/${p.season_id}/${p.id}.png`;
  }

  clubLogoUrl(p: FreeAgent): string | null {
    if (!p.club_logo_uploaded) return null;
    return `https://img.die-bestesten.de/img/club/${p.club_id}.png`;
  }

  togglePosition(pos: string): void {
    this.positionFilter.set(this.positionFilter() === pos ? null : pos);
  }

  toggleClub(id: string): void {
    this.clubFilter.set(this.clubFilter() === id ? null : id);
  }

  onPriceInput(event: Event): void {
    const val = +(event.target as HTMLInputElement).value;
    this.maxPrice.set(val >= this.maxDataPrice() ? null : val);
  }

  resetFilters(): void {
    this.searchQuery.set('');
    this.positionFilter.set(null);
    this.clubFilter.set(null);
    this.maxPrice.set(null);
  }

  formatPrice(v: number): string {
    return v.toLocaleString('de-DE') + ' €';
  }
}
