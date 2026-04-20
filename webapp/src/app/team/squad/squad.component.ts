import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

@Component({
  selector: 'app-squad',
  standalone: false,
  templateUrl: './squad.component.html',
  styleUrl: './squad.component.scss'
})
export class SquadComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);

  private id$ = this.route.parent!.paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<any>(`player_in_team?team_id=${id}&include_former=1`).pipe(
          map(data => ({ current: data.current as any[], former: data.former as any[], loading: false, error: null as string | null })),
          startWith({ current: [] as any[], former: [] as any[], loading: true, error: null as string | null }),
          catchError(() => of({ current: [] as any[], former: [] as any[], loading: false, error: 'Fehler beim Laden' }))
        )
      )
    ),
    { initialValue: { current: [] as any[], former: [] as any[], loading: true, error: null as string | null } }
  );

  players = computed(() => this.state().current);
  former  = computed(() => this.state().former);
  loading = computed(() => this.state().loading);
  error   = computed(() => this.state().error);

  positionLabel(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'TOR',
      DEFENDER:   'ABW',
      MIDFIELDER: 'MIT',
      FORWARD:    'STU',
    };
    return map[pos] ?? pos;
  }

  positionColor(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'var(--position-goalkeeper)',
      DEFENDER:   'var(--position-defender)',
      MIDFIELDER: 'var(--position-midfielder)',
      FORWARD:    'var(--position-forward)',
    };
    return map[pos] ?? 'transparent';
  }

  photoUrl(p: any): string | null {
    if (!p.photo_uploaded) return null;
    return `https://img.die-bestesten.de/img/player/${p.season_id}/${p.id}.png`;
  }

  clubLogoUrl(p: any): string {
    if (!p.current_club_id || !p.club_logo_uploaded) return 'img/placeholders/club.png';
    return `https://img.die-bestesten.de/img/club/${p.current_club_id}.png`;
  }

  formatPrice(price: number | null): string {
    if (price == null) return '—';
    if (price >= 1_000_000) return (price / 1_000_000).toFixed(1).replace('.', ',') + ' Mio.';
    if (price >= 1_000)     return (price / 1_000).toFixed(0) + ' Tsd.';
    return price.toString();
  }
}
