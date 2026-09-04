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
  targetTeamId: string; // team.id des betroffenen Teams — reicht, da dieser Inhalt ohnehin nur für die aktuelle Saison gilt
  text: string;
  status: HotTakeStatus;
}

interface Bet {
  managerAId: string;
  managerBId: string;
  condition: string; // Freitext: wer gewinnt unter welcher Bedingung/bis wann
}

const LUKAS_ID = '36caa3ac-2f9b-4994-833b-8b647d4fc445';
const SCHLAGGY_ID = 'ab59bee2-aaa2-4292-a6df-e1dfc7e296f6';

// Rein hartkodierter, saisonaler Inhalt (siehe DataCacheService.isHotTakesLeague) — kein
// Datenbank-Modell, wird nach der Saison einfach wieder aus dem Quellcode entfernt. Wird in
// Folge-Turns befüllt.
const HOT_TAKES: { authorManagerId: string; takes: HotTake[] }[] = [
  {
    authorManagerId: LUKAS_ID,
    takes: [
      {
        targetTeamId: '129c1cf4-5ec6-4947-8c2c-50df149be9d4',
        text: 'Ich habe am Ende 3 150Pkt Spieler',
        status: 'pending',
      }, // Sackflanke
      {
        targetTeamId: 'ce11729a-cdd2-43d0-bfc8-4edaad9f5949',
        text: 'Petkov bester Mittelfeldspieler bei Eike',
        status: 'pending',
      }, // Kackbratzen
      {
        targetTeamId: '9153be2f-37b1-42c1-9103-167717e78ce7',
        text: 'Die meisten Kaderrotationen + Bolin macht 100 Punkte und wird damit sein zweitbester Mittelfeldspieler',
        status: 'pending',
      }, // US Töfte Calcio
      {
        targetTeamId: '37d712d3-4c34-472f-a46f-72f2cc99f1c3',
        text: 'Mohya startet durch und wird von Klopp zur Nationalmannschaft eingeladen',
        status: 'pending',
      }, // ZSG Fortschritt Achmer
      {
        targetTeamId: '863867f0-9691-4808-b411-a15bb25bbd9b',
        text: 'Kein Stürmer macht mehr als 50 Punkte',
        status: 'pending',
      }, // Bonn 17
      {
        targetTeamId: '81123688-0f53-47b6-a92e-b64628c635e5',
        text: 'wird seine beste Saison bisher (>1.330)',
        status: 'pending',
      }, // Blutgrätsche 69
      {
        targetTeamId: '80778c37-a22a-40a3-acd7-b52c1845bb22',
        text: 'Fab macht seinen ersten Transfer erst wieder im Winter',
        status: 'pending',
      }, // Fab
      {
        targetTeamId: 'da221851-6aca-4499-b830-d47adaf5f07c',
        text: 'Von den 4 Stürmern spielen nie 3 gleichzeitig in der S11',
        status: 'pending',
      }, // Fiasko Fantasto
      {
        targetTeamId: 'cd3e7c38-b50b-4388-8e58-e0363ecdf990',
        text: 'Nicht mehr als zwei gleichzeitige S11-Einsätze von Pavlovic, Konstantelias, Lokonga und Burkhardt',
        status: 'pending',
      }, // Concordia Hachmannsfeld
      {
        targetTeamId: '31c26702-9842-471c-a5bb-7b57a9561c87',
        text: 'Beste Abwehr der Liga',
        status: 'pending',
      }, // Schlaggy
    ],
  },
  {
    authorManagerId: SCHLAGGY_ID,
    takes: [
      {
        targetTeamId: 'f30e89d4-11f0-4fad-b98a-ac7970f11363',
        text: 'Olise wird punktbester Spieler mit >300',
        status: 'pending',
      }, // SV Spielabbruch
      {
        targetTeamId: '37d712d3-4c34-472f-a46f-72f2cc99f1c3',
        text: 'Er hat immer einen zweistelligen Tabellenplatz',
        status: 'pending',
      }, // ZSG Fortschritt Achmer
      {
        targetTeamId: '129c1cf4-5ec6-4947-8c2c-50df149be9d4',
        text: 'Guirassy macht keine 100 Punkte',
        status: 'pending',
      }, // Sackflanke
      {
        targetTeamId: '0bdd048d-973e-4593-9fbf-96d2476c5771',
        text: 'Keine 25 Punkte Unterschied zu Nils',
        status: 'pending',
      }, // Rosamunde Pilsner
      {
        targetTeamId: '81123688-0f53-47b6-a92e-b64628c635e5',
        text: 'Sturm macht mehr als MF + Abwehr zusammen',
        status: 'pending',
      }, // Blutgrätsche 69
      {
        targetTeamId: 'ce11729a-cdd2-43d0-bfc8-4edaad9f5949',
        text: 'Verteidigt die Bürste - und Königsdörffer ist aufgestellt',
        status: 'pending',
      }, // Kackbratzen
      {
        targetTeamId: 'cd3e7c38-b50b-4388-8e58-e0363ecdf990',
        text: 'Punktbeste Abwehr der Liga (nur die drei - Upamecano, Tapsoba & Miguel)',
        status: 'pending',
      }, // Concordia Hachmannsfeld
      {
        targetTeamId: '863867f0-9691-4808-b411-a15bb25bbd9b',
        text: 'Unter den drei, die am wenigsten zahlen',
        status: 'pending',
      }, // Bonn 17
      {
        targetTeamId: '9153be2f-37b1-42c1-9103-167717e78ce7',
        text: 'Kauft noch Grifo, und der wird hinter Pejcinovic sein punktbester Spieler',
        status: 'pending',
      }, // US Töfte Calcio
      {
        targetTeamId: '80778c37-a22a-40a3-acd7-b52c1845bb22',
        text: 'Beide Bayern-Spieler machen >33% der Punkte',
        status: 'pending',
      }, // Fab
      {
        targetTeamId: 'da221851-6aca-4499-b830-d47adaf5f07c',
        text: 'Wir sehen noch die höchste Ablöse der Saison',
        status: 'pending',
      }, // Fiasko Fantasto
    ],
  },
];
const BETS: Bet[] = [
  {
    managerAId: SCHLAGGY_ID,
    managerBId: LUKAS_ID,
    condition: 'Rocco porno Reiz\n>124 Punkte: Sieg Lukas\n<125 Punkte: Sieg Schlaggy',
  },
];

@Component({
  selector: 'app-hot-takes',
  standalone: false,
  templateUrl: './hot-takes.component.html',
  styleUrl: './hot-takes.component.scss',
})
export class HotTakesComponent {
  private api = inject(ApiService);
  private router = inject(Router);
  cache = inject(DataCacheService);

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

  private activeSeasonId = computed(
    () =>
      [...this.cache.seasons()].sort((a, b) => b.start_date.localeCompare(a.start_date))[0]?.id ??
      null,
  );

  private teamsState = toSignal(
    toObservable(this.activeSeasonId).pipe(
      switchMap((id) => {
        if (!id) return of([] as LigaTeamLite[]);
        return this.api
          .get<LigaTeamLite[]>(`team?season_id=${id}`)
          .pipe(catchError(() => of([] as LigaTeamLite[])));
      }),
    ),
    { initialValue: [] as LigaTeamLite[] },
  );

  teams = computed(() =>
    [...this.teamsState()].sort((a, b) => a.team_name.localeCompare(b.team_name)),
  );

  private teamByManagerId = computed(() => {
    const map = new Map<string, LigaTeamLite>();
    for (const t of this.teams()) map.set(t.manager_id, t);
    return map;
  });

  authorInfo(managerId: string): { manager_name: string; alias: string | null } {
    const team = this.teamByManagerId().get(managerId);
    return team
      ? { manager_name: team.manager_name, alias: team.alias }
      : { manager_name: '—', alias: null };
  }

  managerInfo(managerId: string): { manager_name: string; alias: string | null } {
    return this.authorInfo(managerId);
  }

  takeFor(authorManagerId: string, targetTeamId: string): HotTake | undefined {
    return HOT_TAKES.find((a) => a.authorManagerId === authorManagerId)?.takes.find(
      (t) => t.targetTeamId === targetTeamId,
    );
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
  logoFailed(teamId: string): boolean {
    return this.logoErrors.has(teamId);
  }
  onLogoError(teamId: string): void {
    this.logoErrors.add(teamId);
  }

  avatarFailed = new Set<string>();
  onAvatarError(managerId: string): void {
    this.avatarFailed.add(managerId);
  }
}
