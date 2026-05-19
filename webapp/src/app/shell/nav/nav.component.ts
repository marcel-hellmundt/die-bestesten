import { Component, computed, effect, inject } from '@angular/core';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';

interface NavItem {
  label: string;
  icon: string;
  route: string | any[] | null;
  warning?: boolean;
}

interface NavGroup {
  label: string;
  icon?: string;
  items: NavItem[];
}

@Component({
  selector: 'app-nav',
  standalone: false,
  templateUrl: './nav.component.html',
  styleUrl: './nav.component.scss'
})
export class NavComponent {
  private auth  = inject(AuthService);
  private cache = inject(DataCacheService);

  managerName = this.auth.getManagerName();
  managerId   = this.auth.getManagerId();
  teamName    = computed(() => this.cache.myTeam()?.team_name ?? '');
  teamId      = computed(() => this.cache.myTeamId());

  teamGroups = computed<NavGroup[]>(() => {
    const id = this.cache.myTeamId();
    return [
      {
        label: 'Team',
        icon: 'kader',
        items: [
          { label: 'Übersicht',   icon: 'uebersicht',  route: id ? ['/team', id, 'uebersicht']  : null },
          { label: 'Kader',       icon: 'kader',       route: id ? ['/team', id, 'kader']       : null, warning: this.cache.squadInvalid() },
          { label: 'Aufstellung', icon: 'aufstellung', route: id ? ['/team', id, 'aufstellung']  : null },
          { label: 'Finanzen',    icon: 'finanzen',    route: id ? ['/team', id, 'finanzen']    : null },
        ]
      }
    ];
  });

  readonly ligaGroup: NavGroup = {
    label: 'Liga',
    icon: 'tabelle',
    items: [
      { label: 'Spieltag',    icon: 'spieltag',    route: '/liga/spieltag' },
      { label: 'Tabelle',     icon: 'tabelle',     route: '/liga/tabelle' },
      { label: 'H2H',         icon: 'zap',         route: '/liga/h2h' },
      { label: 'Teams',       icon: 'kader',       route: '/liga/teams' },
      { label: 'Ruhmeshalle', icon: 'ruhmeshalle', route: '/liga/ruhmeshalle' },
    ]
  };

  readonly marktGroup: NavGroup = {
    label: 'Markt',
    icon: 'transferphasen',
    items: [
      { label: 'Spieler',        icon: 'spieler',        route: '/markt/spieler' },
      { label: 'Transferphasen', icon: 'transferphasen', route: '/markt/transferphasen' },
      { label: 'Gebote',         icon: 'gebote',         route: '/markt/gebote' },
      { label: 'Scouting',       icon: 'eye',            route: '/scouting' },
    ]
  };

  topGroups = computed<NavGroup[]>(() => [
    this.ligaGroup,
    ...this.teamGroups(),
    this.marktGroup,
  ]);

  bottomGroups = computed<NavGroup[]>(() => [
    {
      label: '',
      items: [
        ...(this.auth.isMaintainer() ? [{ label: 'Datenbank', icon: 'data', route: '/daten' } as NavItem] : []),
        { label: 'Einstellungen', icon: 'settings', route: '/einstellungen' },
      ]
    }
  ]);

  mobileNavItems = computed<NavItem[]>(() =>
    this.topGroups().map(g => ({
      label: g.label,
      icon: g.icon ?? g.items[0].icon,
      route: g.items[0].route,
    }))
  );

  constructor() {
    this.cache.ensureMyTeam();
    effect(() => {
      if (this.cache.myTeamId()) this.cache.ensureSquad();
    });
  }
}

