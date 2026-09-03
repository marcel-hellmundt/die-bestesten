import { Component, computed, effect, inject } from '@angular/core';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';

interface NavItem {
  label: string;
  icon: string;
  route: string | any[] | null;
  warning?: boolean;
  isNew?: boolean;
}

interface NavGroup {
  label: string;
  icon?: string;
  // Route für den mobilen Bottom-Bar-Tab dieser Gruppe — fällt sonst auf items[0].route zurück.
  // Nötig, weil die Reihenfolge in items[] rein für die Desktop-Sidebar/Pill-Nav-Anzeige gilt und
  // nicht zwingend die gewünschte Mobile-Standardseite widerspiegelt (z.B. könnte items[0] durch
  // bedingte Einträge am Anfang der Liste variieren, während der Liga-Tab immer auf Spieltag
  // öffnen soll).
  mobileRoute?: string | any[];
  items: NavItem[];
}

@Component({
  selector: 'app-nav',
  standalone: false,
  templateUrl: './nav.component.html',
  styleUrl: './nav.component.scss',
})
export class NavComponent {
  private auth = inject(AuthService);
  private cache = inject(DataCacheService);

  managerName = this.auth.getManagerName();
  managerId = this.auth.getManagerId();
  teamName = computed(() => this.cache.myTeam()?.team_name ?? '');
  teamId = computed(() => this.cache.myTeamId());

  teamGroups = computed<NavGroup[]>(() => {
    const id = this.cache.myTeamId();
    return [
      {
        label: 'Team',
        icon: 'kader',
        items: [
          {
            label: 'Übersicht',
            icon: 'uebersicht',
            route: id ? ['/team', id, 'uebersicht'] : null,
          },
          {
            label: 'Kader',
            icon: 'kader',
            route: id ? ['/team', id, 'kader'] : null,
            warning: this.cache.squadInvalid(),
          },
          {
            label: 'Aufstellung',
            icon: 'aufstellung',
            route: id ? ['/team', id, 'aufstellung'] : null,
            warning: this.cache.lineupInvalid(),
          },
          { label: 'Finanzen', icon: 'finanzen', route: id ? ['/team', id, 'finanzen'] : null },
        ],
      },
    ];
  });

  ligaGroup = computed<NavGroup>(() => ({
    label: 'Liga',
    icon: 'tabelle',
    mobileRoute: '/liga/spieltag',
    items: [
      { label: 'Spieltag', icon: 'spieltag', route: '/liga/spieltag' },
      { label: 'Tabelle', icon: 'tabelle', route: '/liga/tabelle' },

      ...(this.cache.h2hTournamentEverExisted()
        ? [{ label: 'H2H', icon: 'zap', route: '/liga/h2h' } as NavItem]
        : []),
      { label: 'Teams', icon: 'kader', route: '/liga/teams' },
      ...(this.cache.powerrankingEnabled()
        ? [{ label: 'Powerranking', icon: 'powerranking', route: '/liga/powerranking' } as NavItem]
        : []),
      { label: 'Ruhmeshalle', icon: 'ruhmeshalle', route: '/liga/ruhmeshalle' },
      ...(this.cache.isHotTakesLeague()
        ? [{ label: 'Hot-Takes & Wetten', icon: 'statistiken', route: '/liga/hot-takes' } as NavItem]
        : []),
    ],
  }));

  readonly marktGroup: NavGroup = {
    label: 'Markt',
    icon: 'transferphasen',
    items: [
      { label: 'Spieler', icon: 'spieler', route: '/markt/spieler' },
      { label: 'Transferphasen', icon: 'transferphasen', route: '/markt/transferphasen' },
      { label: 'Gebote', icon: 'gebote', route: '/markt/gebote' },
      { label: 'Scouting', icon: 'eye', route: '/markt/scouting' },
    ],
  };

  topGroups = computed<NavGroup[]>(() => [this.ligaGroup(), ...this.teamGroups(), this.marktGroup]);

  bottomGroups = computed<NavGroup[]>(() =>
    this.auth.isContributor() ? [{ label: '', items: [{ label: 'Datenbank', icon: 'data', route: '/daten' }] }] : [],
  );

  mobileNavItems = computed<NavItem[]>(() =>
    this.topGroups().map((g) => ({
      label: g.label,
      icon: g.icon ?? g.items[0].icon,
      route: g.mobileRoute ?? g.items[0].route,
    })),
  );

  constructor() {
    this.cache.ensureMyTeam();
    this.cache.ensureH2HStatus();
    this.cache.ensureLeague();
    effect(() => {
      if (this.cache.myTeamId()) {
        this.cache.ensureSquad();
        this.cache.ensureLineup();
      }
    });
  }
}
