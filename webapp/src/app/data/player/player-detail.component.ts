import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

interface PlayerInSeason {
  season_id: string;
  price: number;
  position: 'GOALKEEPER' | 'DEFENDER' | 'MIDFIELDER' | 'FORWARD';
  photo_uploaded: number;
  season_start: string;
  total_points: string;
}

interface PlayerInClub {
  club_id: string;
  club_name: string;
  logo_uploaded: number;
  from_date: string;
  to_date: string | null;
  on_loan: number;
}

interface PlayerRating {
  id: string;
  grade: string | null;
  participation: 'starting' | 'substitute' | null;
  goals: string;
  assists: string;
  clean_sheet: string;
  sds: string;
  red_card: string;
  yellow_red_card: string;
  points: string | null;
  matchday_number: string;
  kickoff_date: string;
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
  ratings: PlayerRating[];
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

  selectedSeasonId = signal<string | null>(null);
  private selectedSeason$ = toObservable(this.selectedSeasonId);

  private state = toSignal(
    combineLatest([this.id$, this.selectedSeason$]).pipe(
      switchMap(([id, seasonId]) => {
        const url = seasonId ? `player/${id}?season_id=${seasonId}` : `player/${id}`;
        return this.api.get<PlayerDetail>(url).pipe(
          map((data) => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as PlayerDetail | null, loading: true, error: null as string | null }),
          catchError(() =>
            of({ data: null as PlayerDetail | null, loading: false, error: 'Fehler beim Laden' }),
          ),
        );
      }),
    ),
  );

  player = computed(() => this.state()?.data ?? null);
  loading = computed(() => this.state()?.loading ?? true);
  error = computed(() => this.state()?.error ?? null);

  latestPhotoUrl = computed(() => {
    const p = this.player();
    if (!p) return null;
    const latest = p.seasons[0]; // sorted newest first
    if (!latest?.photo_uploaded) return null;
    return `https://img.die-bestesten.de/img/player/${latest.season_id}/${p.id}.png`;
  });

  private readonly positionColors: Record<string, string> = {
    FORWARD:    'var(--position-forward)',
    MIDFIELDER: 'var(--position-midfielder)',
    DEFENDER:   'var(--position-defender)',
    GOALKEEPER: 'var(--position-goalkeeper)',
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

  range(n: number | string): number[] {
    return Array.from({ length: Math.max(0, +n) }, (_, i) => i);
  }

  gradeVar(grade: string | null): string {
    if (!grade) return 'var(--grade-unset)';
    return `var(--grade-${grade.replace('.', '')})`;
  }

  // Points bar chart (per matchday, colored by grade)
  pointsChartData = computed(() => {
    const p = this.player();
    if (!p || p.ratings.length === 0) return null;

    const sorted = p.ratings; // already sorted by matchday_number ASC
    const rawPts = sorted.map((r) => +(r.points ?? 0));
    const maxPts = Math.max(...rawPts, 0);
    const minPts = Math.min(...rawPts, 0);
    const range  = Math.max(maxPts - minPts, 1);

    const plotW  = this.pointsChartW - this.padL - this.padR;
    const plotH  = this.chartH - this.padT - this.padB;
    const n      = sorted.length;
    const slotW  = plotW / n;
    const barW   = Math.min(slotW * 0.65, 40);

    // Y coordinate of the zero baseline
    const zeroY = this.padT + plotH * (maxPts / range);

    const bars = sorted.map((s, i) => {
      const pts  = +(s.points ?? 0);
      const barH = (Math.abs(pts) / range) * plotH;
      const x    = this.padL + i * slotW + (slotW - barW) / 2;
      const y    = pts >= 0 ? zeroY - barH : zeroY;
      return {
        x, y, width: barW, height: barH,
        color:   s.grade ? this.gradeVar(s.grade) : '#9ca3af',
        labelX:  this.padL + i * slotW + slotW / 2,
        label:   s.matchday_number,
        tooltip: `ST ${s.matchday_number}: ${pts} Pkt`,
      };
    });

    const yTicks: { y: number; label: string }[] = [
      { y: zeroY, label: '0' },
    ];
    if (maxPts > 0) yTicks.unshift({ y: this.padT,        label: String(maxPts) });
    if (minPts < 0) yTicks.push(  { y: this.padT + plotH, label: String(minPts) });

    // Rolling average line (5-matchday window)
    const windowSize = 5;
    const rollingPts: Array<{ x: number; y: number }> = [];
    for (let i = windowSize - 1; i < sorted.length; i++) {
      const avg = rawPts.slice(i - windowSize + 1, i + 1).reduce((a, b) => a + b, 0) / windowSize;
      rollingPts.push({
        x: this.padL + i * slotW + slotW / 2,
        y: Math.max(this.padT, Math.min(this.padT + plotH, zeroY - (avg / range) * plotH)),
      });
    }
    const rollingLine = rollingPts.length >= 2
      ? rollingPts.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ')
      : null;

    return { bars, yTicks, rollingLine };
  });

  // Bar charts – widths tuned to their respective CSS containers
  // price chart: 1/3 grid column ≈ 320px; points chart: full-width ≈ 900px
  readonly chartW       = 380; // price chart (middle grid column)
  readonly pointsChartW = 900; // points chart (full-width row above grid)
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
