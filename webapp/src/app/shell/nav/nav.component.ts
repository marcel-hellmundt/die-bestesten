import { Component, computed, inject } from '@angular/core';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';

interface NavItem {
  label: string;
  icon: string;
  route: string | any[];
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

  teamGroups = computed<NavGroup[]>(() => {
    const id = this.cache.myTeamId();
    return [
      {
        label: 'Team',
        icon: 'kader',
        items: [
          { label: 'Kader',       icon: 'kader',       route: id ? ['/team', id, 'kader']       : [] },
          { label: 'Aufstellung', icon: 'aufstellung', route: id ? ['/team', id, 'aufstellung']  : [] },
          { label: 'Finanzen',    icon: 'finanzen',    route: id ? ['/team', id, 'finanzen']     : [] },
          { label: 'Statistiken', icon: 'statistiken', route: id ? ['/team', id, 'statistiken']  : [] },
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
      { label: 'Statistiken', icon: 'statistiken', route: '/liga/statistiken' },
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
  }
}

