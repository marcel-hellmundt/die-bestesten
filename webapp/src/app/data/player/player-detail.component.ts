import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

interface PlayerInSeason {
  season_id: string;
  price: number;
  position: 'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD';
  photo_uploaded: number;
  season_start: string;
}

interface PlayerInClub {
  club_id: string;
  club_name: string;
  logo_uploaded: number;
  from_date: string;
  to_date: string | null;
  on_loan: number;
}

interface PlayerDetail {
  id: string;
  country_id: string | null;
  first_name: string | null;
  last_name: string | null;
  displayname: string;
  birth_city: string | null;
  date_of_birth: string | null;
  height_cm: number | null;
  weight_kg: number | null;
  seasons: PlayerInSeason[];
  clubs: PlayerInClub[];
}

@Component({
  selector: 'app-player-detail',
  standalone: false,
  templateUrl: './player-detail.component.html',
  styleUrl: './player-detail.component.scss',
})
export class PlayerDetailComponent {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  cache = inject(DataCacheService);

  private id$ = this.route.paramMap.pipe(map((p) => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap((id) =>
        this.api.get<PlayerDetail>(`player/${id}`).pipe(
          map((data) => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as PlayerDetail | null, loading: true, error: null as string | null }),
          catchError(() =>
            of({ data: null as PlayerDetail | null, loading: false, error: 'Fehler beim Laden' }),
          ),
        ),
      ),
    ),
  );

  player = computed(() => this.state()?.data ?? null);
  loading = computed(() => this.state()?.loading ?? true);
  error = computed(() => this.state()?.error ?? null);

  private readonly positionColors: Record<string, string> = {
    FORWARD: '#ff3f34',
    MIDFIELDER: '#575fcf',
    DEFENDER: '#ffd32a',
    GOALKEEPER: '#05c46b',
  };

  private readonly positionLabels: Record<string, string> = {
    FORWARD: 'Sturm',
    MIDFIELDER: 'Mittelfeld',
    DEFENDER: 'Abwehr',
    GOALKEEPER: 'Tor',
  };

  positionColor(position: string): string {
    return this.positionColors[position] ?? '#999';
  }

  positionLabel(position: string): string {
    return this.positionLabels[position] ?? position;
  }

  clubLogoUrl(clubId: string, logoUploaded: number): string {
    return logoUploaded
      ? `https://img.die-bestesten.de/img/club/${clubId}.png`
      : 'img/placeholders/club.png';
  }

  formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
  }

  formatPrice(price: number): string {
    return new Intl.NumberFormat('de-DE', {
      style: 'currency',
      currency: 'EUR',
      maximumFractionDigits: 0,
    }).format(price);
  }

  constructor() {
    this.cache.ensureSeasons();
  }
}
