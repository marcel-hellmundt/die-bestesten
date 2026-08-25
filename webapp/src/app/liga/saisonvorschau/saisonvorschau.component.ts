import {
  Component,
  ElementRef,
  HostListener,
  ViewChild,
  computed,
  inject,
  signal,
} from '@angular/core';
import { Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { environment } from '../../../environments/environment';

interface TooltipPlayer {
  name: string;
  club_id: string | null;
  club_logo_uploaded: boolean;
  points?: number;
  position?: string;
}

interface PointsBreakdown {
  all: TooltipPlayer[];
  value11: TooltipPlayer[];
  best11: TooltipPlayer[];
}

interface SaisonvorschauTeam {
  id: string;
  team_name: string;
  color: string | null;
  color_secondary: string | null;
  manager_id: string;
  manager_name: string;
  alias: string | null;
  squad_valid: boolean;
  position_counts: Record<string, number>;
  previous_season_points: number;
  previous_season_points_value11: number | null;
  previous_season_points_best11: number | null;
  points_breakdown: PointsBreakdown;
  newcomer_count: number;
  newcomer_players: TooltipPlayer[];
}

interface ClubRef {
  id: string;
  name: string;
  short_name?: string | null;
  logo_uploaded: boolean;
}

interface ClubTeamCount {
  team_id: string;
  team_name: string;
  color: string | null;
  color_secondary: string | null;
  count: number;
  players: TooltipPlayer[];
}

interface SaisonvorschauResponse {
  season_id: string | null;
  previous_season_id: string | null;
  available: boolean;
  kickoff_date: string | null;
  teams: SaisonvorschauTeam[];
  promoted_clubs: ClubRef[];
  promoted_club_teams: ClubTeamCount[];
  special_clubs: ClubRef[];
  special_club_teams: ClubTeamCount[];
}

interface InterviewMessage {
  sender: 'diebestesten' | 'manager';
  paragraphs: string[];
}

interface GalleryPhoto {
  url: string;
  caption: string;
}

type SortField = 'previous_season_points' | 'newcomer_count';
type PointsMode = 'all' | 'value11' | 'best11';

const POSITIONS = ['GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'];
const SQUAD_MIN: Record<string, number> = { GOALKEEPER: 1, DEFENDER: 5, MIDFIELDER: 5, FORWARD: 3 };

@Component({
  selector: 'app-saisonvorschau',
  standalone: false,
  templateUrl: './saisonvorschau.component.html',
  styleUrl: './saisonvorschau.component.scss',
})
export class SaisonvorschauComponent {
  private api = inject(ApiService);
  private router = inject(Router);

  private state = toSignal(
    this.api.get<SaisonvorschauResponse>('saisonvorschau').pipe(
      map((data) => ({ data, loading: false, error: null as string | null })),
      startWith({
        data: null as SaisonvorschauResponse | null,
        loading: true,
        error: null as string | null,
      }),
      catchError(() => of({ data: null, loading: false, error: 'Fehler beim Laden' })),
    ),
    {
      initialValue: {
        data: null as SaisonvorschauResponse | null,
        loading: true,
        error: null as string | null,
      },
    },
  );

  loading = computed(() => this.state().loading);
  error = computed(() => this.state().error);
  available = computed(() => this.state().data?.available ?? true);
  seasonId = computed(() => this.state().data?.season_id ?? null);
  teams = computed(() => this.state().data?.teams ?? []);

  // Redaktioneller Sommer-Interview-Block unter den Karten — statischer Inhalt, kein API-Feld.
  readonly interviewManagerName = 'Thommy';
  readonly interviewManagerId = '46b65ef1-2df1-4956-be67-0e16a16a51a2';
  readonly interviewManagerPhotoUrl = `${environment.imageApiUrl}/manager/${this.interviewManagerId}.jpg`;
  interviewPhotoFailed = signal(false);

  readonly interviewMessages: InterviewMessage[] = [
    {
      sender: 'diebestesten',
      paragraphs: [
        'Servus Thommy, Glückwunsch nochmal zum Pokal! Aber lassen wir die Jubelbilder im Archiv: Die Konkurrenz in DieBestesten hat über den Sommer aufgerüstet und jagt dich jetzt.',
        'Erste Frage: Titel verteidigen ist bekanntlich schwerer als ihn zu holen – wo hat dein Kader aktuell noch eine Baustelle, und schläfst du vor dem Saisonstart wirklich so ruhig, wie du tust?',
      ],
    },
    {
      sender: 'manager',
      paragraphs: [
        'Naja, aktuell ist der Kader ungefähr so fertig wie das Kreuz Leverkusen. Trotzdem bin ich tatsächlich so entspannt wie Nils nach einem Zug aus der Elfbar Traube. Das ist zwar nicht optimal, aber auch ein Stück weit einkalkuliert. Das ganze lebt davon, die Ruhe zu bewahren. In der zweiten Phase war es nach dem Totalausfall in der ersten mal kurz davor, dass das nicht gelingt. Aber das hat sich dann auch schnell wieder gelegt. Insgesamt gibt auch der dritte Titel Ruhe, der hat ja nun doch lange auf sich warten lassen.',
      ],
    },
    {
      sender: 'diebestesten',
      paragraphs: [
        'Nach dem Titel ist vor der Kaderplanung: Wie läuft dein Sommertransferfenster bisher ab? Hast du ein klares System aus knallharten Daten und Scouting, oder verlässt du dich beim Teamaufbau auf dein bewährtes Bauchgefühl?',
      ],
    },
    {
      sender: 'manager',
      paragraphs: [
        'Ich möchte da natürlich nicht zu viel verraten. Nur so viel: Ich habe mir keine Kloppo-Shortlist mit 56 Spielern gemacht. Vorbereitungszeit war sehr gering. Ich gehe das eher strategisch auf die Phasen an - was letzte Saison hervorragend geklappt hat, in dieser aber zunächst nicht ganz. Alles andere ist überwiegend Bauchgefühl und das, was ich vermehrt in der Saisonvorbereitung aufgenommen habe. Ich mache da im Vorfeld nicht mehr so ein riesen Fass auf. Das Wichtigste ist, dass das Menschliche stimmt. Ein Spieler muss charakterlich zur Mannschaft passen.',
      ],
    },
    {
      sender: 'diebestesten',
      paragraphs: [
        'Und zum Abschluss kommen wir zu einem echten Highlight der neuen Saison: Der H2H-Modus feiert Premiere! Freust du dich schon auf die direkten Duelle – und hast du vor, die Chance zu nutzen, dem Präsidenten im direkten Duell zu zeigen, was es heißt Managerskills zu haben - oder eben nicht?',
      ],
    },
    {
      sender: 'manager',
      paragraphs: [
        'Dieses Beweises ist in diesem Duell auf lange Zeit erstmal nur einer schuldig. Und der dreht nach 10 Halben gern mal an der imaginären Schraube. Der H2H-Modus begeistert aber natürlich dennoch auf ganzer Linie. Er ist die Kirsche auf der Sahne, das Salz in der Suppe und ganz besondere Kick aus der Whatsapp-Gruppe. Ich freue mich auf die Duelle.',
      ],
    },
  ];

  readonly galleryPhotos: GalleryPhoto[] = [
    {
      url: 'https://i.imgur.com/4vvC6l5.jpeg',
      caption: 'Neues Trikot von US Töfte Calcio in der Saison 26/27',
    },
    { url: 'https://i.imgur.com/5mqFoxD.jpeg', caption: 'Titelverteidigung möglich?' },
  ];
  galleryIndex = signal(0);

  galleryPrev(): void {
    this.galleryIndex.update(
      (i) => (i - 1 + this.galleryPhotos.length) % this.galleryPhotos.length,
    );
  }

  galleryNext(): void {
    this.galleryIndex.update((i) => (i + 1) % this.galleryPhotos.length);
  }

  promotedClubs = computed(() => this.state().data?.promoted_clubs ?? []);
  promotedClubTeams = computed(() => this.state().data?.promoted_club_teams ?? []);
  specialClubs = computed(() => this.state().data?.special_clubs ?? []);
  specialClubTeams = computed(() => this.state().data?.special_club_teams ?? []);

  sortField = signal<SortField>('previous_season_points');
  sortDir = signal<'asc' | 'desc'>('desc');

  // "Punkte Vorsaison" hat drei Berechnungsmodi (Toggle über der Tabelle) — welche Spieler
  // eines Kaders in die Summe einfließen. Reiner Anzeige-/Sortier-Switch, kein Refetch: das
  // Backend liefert alle drei Werte immer mit.
  pointsMode = signal<PointsMode>('all');

  pointsValue(t: SaisonvorschauTeam): number | null {
    switch (this.pointsMode()) {
      case 'value11':
        return t.previous_season_points_value11;
      case 'best11':
        return t.previous_season_points_best11;
      default:
        return t.previous_season_points;
    }
  }

  pointsBreakdown(t: SaisonvorschauTeam): TooltipPlayer[] {
    return t.points_breakdown[this.pointsMode()];
  }

  pointsModeLabel(): string {
    switch (this.pointsMode()) {
      case 'value11':
        return 'Teuerste 11';
      case 'best11':
        return 'Beste 11';
      default:
        return 'Alle Spieler';
    }
  }

  sortedTeams = computed(() => {
    const field = this.sortField();
    const dir = this.sortDir() === 'asc' ? 1 : -1;
    return [...this.teams()].sort((a, b) => {
      const av = field === 'previous_season_points' ? this.pointsValue(a) : a.newcomer_count;
      const bv = field === 'previous_season_points' ? this.pointsValue(b) : b.newcomer_count;
      // Teams ohne erreichbare Formation (null) immer ans Ende, unabhängig von der Sortierrichtung.
      if (av === null && bv === null) return 0;
      if (av === null) return 1;
      if (bv === null) return -1;
      return (av - bv) * dir;
    });
  });

  toggleSort(field: SortField): void {
    if (this.sortField() === field) {
      this.sortDir.update((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      this.sortField.set(field);
      this.sortDir.set('desc');
    }
  }

  private logoErrors = new Set<string>();
  logoFailed(teamId: string): boolean {
    return this.logoErrors.has(teamId);
  }
  onLogoError(teamId: string): void {
    this.logoErrors.add(teamId);
  }

  teamLogoUrl(teamId: string): string {
    return `${environment.imageApiUrl}/team/${this.seasonId()}/${teamId}.png`;
  }

  playerClubLogoUrl(p: TooltipPlayer): string | null {
    if (!p.club_id || !p.club_logo_uploaded) return null;
    return `${environment.imageApiUrl}/club/${p.club_id}.png`;
  }

  clubLogoUrl(c: ClubRef): string | null {
    if (!c.logo_uploaded) return null;
    return `${environment.imageApiUrl}/club/${c.id}.png`;
  }

  positionCounts(t: SaisonvorschauTeam) {
    return POSITIONS.map((pos) => {
      const count = t.position_counts?.[pos] ?? 0;
      const min = SQUAD_MIN[pos];
      return {
        position: pos,
        label: this.positionLabel(pos),
        count,
        min,
        color: this.positionColor(pos),
        opacity: count < min ? 0.3 : 1,
      };
    });
  }

  positionLabel(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'TOR',
      DEFENDER: 'ABW',
      MIDFIELDER: 'MIT',
      FORWARD: 'STU',
    };
    return map[pos] ?? pos;
  }

  positionColor(pos: string): string {
    const map: Record<string, string> = {
      GOALKEEPER: 'var(--position-goalkeeper)',
      DEFENDER: 'var(--position-defender)',
      MIDFIELDER: 'var(--position-midfielder)',
      FORWARD: 'var(--position-forward)',
    };
    return map[pos] ?? 'transparent';
  }

  navigate(teamId: string): void {
    this.router.navigate(['/team', teamId, 'kader']);
  }

  // Muss mit $mobile-breakpoint in _variables.scss (768px) übereinstimmen.
  private static readonly MOBILE_BREAKPOINT_PX = 768;
  isMobile = signal(window.innerWidth <= SaisonvorschauComponent.MOBILE_BREAKPOINT_PX);

  @HostListener('window:resize')
  onResize(): void {
    this.isMobile.set(window.innerWidth <= SaisonvorschauComponent.MOBILE_BREAKPOINT_PX);
  }

  // Mobile: die Tabellenzeile ist kein Link zum Team, sondern klappt stattdessen einen Bereich
  // mit denselben Infos wie der Desktop-Hover-Tooltip auf (Punkte-Berechnung + Neuzugänge) — auf
  // Touch-Geräten gibt es kein Hover, dafür Platz für eine ausklappbare Zeile.
  expandedTeamId = signal<string | null>(null);

  onRowClick(teamId: string): void {
    if (this.isMobile()) {
      this.expandedTeamId.update((id) => (id === teamId ? null : teamId));
    } else {
      this.navigate(teamId);
    }
  }

  // Desktop: die Zeile ist per row-link__anchor jetzt ein echtes <a> (Rechtsklick "In neuem Tab
  // öffnen" / Strg-Klick funktioniert nativ) — stopPropagation verhindert nur, dass der Klick
  // zusätzlich onRowClick auslöst und ein zweites Mal navigiert. Auf Mobile ist [routerLink] auf
  // null gesetzt (kein href, keine Navigation), der Klick bubbelt unverändert zu onRowClick durch,
  // das dort stattdessen die Ausklapp-Zeile umschaltet.
  onTeamLinkClick(event: MouseEvent): void {
    if (!this.isMobile()) {
      event.stopPropagation();
    }
  }

  // Die Punkte-/Neuzugänge-Zellen stoppen die Klick-Propagation auf Desktop (damit ein Klick
  // dort — z.B. während man den Hover-Tooltip inspiziert — nicht versehentlich zum Team
  // navigiert). Auf Mobile gibt es diese Navigation gar nicht (onRowClick expanded stattdessen),
  // deshalb muss der Klick dort ungehindert zur Zeile durchgereicht werden, sonst lässt sich die
  // Zeile über genau diese Zellen nicht aufklappen.
  onCellClick(event: MouseEvent): void {
    if (!this.isMobile()) event.stopPropagation();
  }

  // Spielerlisten-Tooltip — gleiches Positionier-/Edge-Clamp-Muster wie die Kader-Gültigkeit-
  // Tooltip auf /liga/teams (liga-teams.component.ts), nur mit einer kompakten Namensliste statt
  // Bubbles. Ein einziger Tooltip wird von allen vier Hover-Zielen geteilt (Neuzugänge-Spalte,
  // Punkte-Vorsaison-Spalte, sowie die Anzahl in beiden Vereins-Karten) — jeweils mit eigenem
  // Titel/Liste; total (Summe-Fußzeile) nur bei der Punkte-Vorsaison-Aufschlüsselung gesetzt.
  @ViewChild('countTooltipEl') countTooltipEl?: ElementRef<HTMLElement>;
  tooltipData = signal<{ title: string; players: TooltipPlayer[]; total?: number } | null>(null);
  tooltipPos = signal<{ top: number; left: number } | null>(null);
  tooltipBelow = signal(false);
  tooltipReady = signal(false);

  private static readonly TOOLTIP_EDGE_MARGIN = 24;
  private hoverSeq = 0;

  onCountHover(event: MouseEvent, title: string, players: TooltipPlayer[], total?: number): void {
    if (!players.length) return;
    const seq = ++this.hoverSeq;
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.tooltipData.set({ title, players, total });
    this.tooltipBelow.set(false);
    this.tooltipReady.set(false);
    this.tooltipPos.set({ top: rect.top, left: rect.left + rect.width / 2 });

    requestAnimationFrame(() => {
      const el = this.countTooltipEl?.nativeElement;
      if (!el || seq !== this.hoverSeq) return;

      const margin = SaisonvorschauComponent.TOOLTIP_EDGE_MARGIN;
      let top = rect.top;
      let left = rect.left + rect.width / 2;
      let below = false;

      const tipRect = el.getBoundingClientRect();

      if (tipRect.top < margin) {
        below = true;
        top = rect.bottom;
      }

      const halfWidth = tipRect.width / 2;
      const maxLeft = window.innerWidth - margin - halfWidth;
      const minLeft = margin + halfWidth;
      if (left > maxLeft) left = maxLeft;
      if (left < minLeft) left = minLeft;

      this.tooltipBelow.set(below);
      this.tooltipPos.set({ top, left });
      this.tooltipReady.set(true);
    });
  }

  onCountLeave(): void {
    this.hoverSeq++;
    this.tooltipData.set(null);
    this.tooltipPos.set(null);
    this.tooltipReady.set(false);
  }
}
