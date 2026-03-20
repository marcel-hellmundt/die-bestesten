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

  formatPriceShort(price: number): string {
    if (price >= 1_000_000) return (price / 1_000_000).toFixed(1).replace('.', ',') + ' M';
    if (price >= 1_000)     return (price / 1_000).toFixed(0) + ' T';
    return String(price);
  }

  // Bar chart
  readonly chartW = 360;
  readonly chartH = 160;
  readonly padL   = 44;
  readonly padR   = 8;
  readonly padT   = 8;
  readonly padB   = 24;

  priceChartData = computed(() => {
    const p = this.player();
    if (!p || p.seasons.length === 0) return null;

    const sorted = [...p.seasons]
      .filter((s) => s.price > 0)
      .sort((a, b) => a.season_start.localeCompare(b.season_start));

    if (sorted.length === 0) return null;

    const maxPrice = Math.max(...sorted.map((s) => s.price));
    const plotW = this.chartW - this.padL - this.padR;
    const plotH = this.chartH - this.padT - this.padB;
    const n     = sorted.length;
    const slotW = plotW / n;
    const barW  = Math.min(slotW * 0.65, 40);

    const bars = sorted.map((s, i) => {
      const barH   = (s.price / maxPrice) * plotH;
      const x      = this.padL + i * slotW + (slotW - barW) / 2;
      const y      = this.padT + plotH - barH;
      const labelX = this.padL + i * slotW + slotW / 2;
      return {
        x, y, width: barW, height: barH,
        color:   this.positionColors[s.position] ?? '#999',
        label:   this.cache.seasonName(s.season_id),
        labelX,
        tooltip: this.formatPrice(s.price),
      };
    });

    const yTicks = [
      { y: this.padT,            label: this.formatPriceShort(maxPrice) },
      { y: this.padT + plotH,    label: '0' },
    ];

    return { bars, yTicks };
  });

  constructor() {
    this.cache.ensureSeasons();
  }
}
