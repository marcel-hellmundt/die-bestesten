import { Component, computed, effect, inject } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';
import { environment } from '../../../environments/environment';

interface LigaTeamLite {
  id: string;
  team_name: string;
  color: string | null;
  season_id: string;
  manager_id: string;
  manager_name: string;
  alias: string | null;
}

type HotTakeStatus = 'pending' | 'true' | 'false';

interface HotTake {
  targetManagerId: string; // Team, um das es geht — via manager_id identifiziert, stabil über Saisons hinweg
  text: string;
  status: HotTakeStatus;
}

interface Bet {
  managerAId: string;
  managerBId: string;
  condition: string; // Freitext: wer gewinnt unter welcher Bedingung/bis wann
}

const LUKAS_ID    = '36caa3ac-2f9b-4994-833b-8b647d4fc445';
const SCHLAGGY_ID = '31c26702-9842-471c-a5bb-7b57a9561c87';

// Rein hartkodierter, saisonaler Inhalt (siehe DataCacheService.isHotTakesLeague) — kein
// Datenbank-Modell, wird nach der Saison einfach wieder aus dem Quellcode entfernt. Wird in
// Folge-Turns befüllt.
const HOT_TAKES: { authorManagerId: string; takes: HotTake[] }[] = [
  { authorManagerId: LUKAS_ID,    takes: [] },
  { authorManagerId: SCHLAGGY_ID, takes: [] },
];
const BETS: Bet[] = [];

@Component({
  selector: 'app-hot-takes',
  standalone: false,
  templateUrl: './hot-takes.component.html',
  styleUrl: './hot-takes.component.scss',
})
export class HotTakesComponent {
  private api    = inject(ApiService);
  private router = inject(Router);
  cache          = inject(DataCacheService);

  readonly authors = [LUKAS_ID, SCHLAGGY_ID] as const;

  constructor() {
    this.cache.ensureSeasons();
    this.cache.ensureLeague();

    // Defensiv: direkter Aufruf der Route in einer anderen Liga soll die Seite nicht anzeigen,
    // auch wenn der Menüpunkt selbst schon gar nicht sichtbar wäre.
    effect(() => {
      if (this.cache.leagueId() !== null && !this.cache.isHotTakesLeague()) {
        this.router.navigate(['/liga']);
      }
    });
  }

  private activeSeasonId = computed(() =>
    [...this.cache.seasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))[0]?.id ?? null
  );

  private teamsState = toSignal(
    toObservable(this.activeSeasonId).pipe(
      switchMap(id => {
        if (!id) return of([] as LigaTeamLite[]);
        return this.api.get<LigaTeamLite[]>(`team?season_id=${id}`).pipe(
          catchError(() => of([] as LigaTeamLite[])),
        );
      }),
    ),
    { initialValue: [] as LigaTeamLite[] },
  );

  teams = computed(() => [...this.teamsState()].sort((a, b) => a.team_name.localeCompare(b.team_name)));

  private teamByManagerId = computed(() => {
    const map = new Map<string, LigaTeamLite>();
    for (const t of this.teams()) map.set(t.manager_id, t);
    return map;
  });

  authorInfo(managerId: string): { manager_name: string; alias: string | null } {
    const team = this.teamByManagerId().get(managerId);
    return team ? { manager_name: team.manager_name, alias: team.alias } : { manager_name: '—', alias: null };
  }

  managerInfo(managerId: string): { manager_name: string; alias: string | null } {
    return this.authorInfo(managerId);
  }

  takeFor(authorManagerId: string, targetManagerId: string): HotTake | undefined {
    return HOT_TAKES
      .find(a => a.authorManagerId === authorManagerId)
      ?.takes.find(t => t.targetManagerId === targetManagerId);
  }

  statusIcon(status: HotTakeStatus | undefined): string {
    if (status === 'true') return 'img/icons/check.png';
    if (status === 'false') return 'img/icons/error.png';
    return 'img/icons/warning.png';
  }

  bets = computed(() => BETS);

  teamLogoUrl(seasonId: string, teamId: string): string {
    return `${environment.imageApiUrl}/team/${seasonId}/${teamId}.png`;
  }

  private logoErrors = new Set<string>();
  logoFailed(teamId: string): boolean { return this.logoErrors.has(teamId); }
  onLogoError(teamId: string): void   { this.logoErrors.add(teamId); }

  avatarFailed = new Set<string>();
  onAvatarError(managerId: string): void { this.avatarFailed.add(managerId); }
}
