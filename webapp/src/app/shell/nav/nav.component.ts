import { Component, inject } from '@angular/core';
import { AuthService } from '../../auth/auth.service';

interface NavItem {
  label: string;
  icon: string;
  route: string;
}

interface NavGroup {
  label: string;
  icon?: string; // used for mobile bottom nav
  items: NavItem[];
}

@Component({
  selector: 'app-nav',
  standalone: false,
  templateUrl: './nav.component.html',
  styleUrl: './nav.component.scss'
})
export class NavComponent {
  private auth = inject(AuthService);

  managerName = this.auth.getManagerName();

  topGroups: NavGroup[] = [
    {
      label: 'Liga',
      icon: 'tabelle',
      items: [
        { label: 'Spieltag',      icon: 'spieltag',      route: '/liga/spieltag' },
        { label: 'Tabelle',       icon: 'tabelle',       route: '/liga/tabelle' },
        { label: 'Statistiken',   icon: 'statistiken',   route: '/liga/statistiken' },
        { label: 'Ruhmeshalle', icon: 'ruhmeshalle', route: '/liga/ruhmeshalle' },
      ]
    },
    {
      label: 'Team',
      icon: 'kader',
      items: [
        { label: 'Kader',       icon: 'kader',       route: '/team/kader' },
        { label: 'Aufstellung', icon: 'aufstellung', route: '/team/aufstellung' },
        { label: 'Finanzen',    icon: 'finanzen',    route: '/team/finanzen' },
        { label: 'Statistiken', icon: 'statistiken', route: '/team/statistiken' },
      ]
    },
    {
      label: 'Markt',
      icon: 'transferphasen',
      items: [
        { label: 'Spieler',        icon: 'spieler',        route: '/markt/spieler' },
        { label: 'Transferphasen', icon: 'transferphasen', route: '/markt/transferphasen' },
        { label: 'Gebote',         icon: 'gebote',         route: '/markt/gebote' },
      ]
    },
  ];

  bottomGroups: NavGroup[] = [
    {
      label: '',
      items: [
        { label: 'Data Management', icon: 'data',     route: '/daten' },
        { label: 'Einstellungen',   icon: 'settings', route: '/einstellungen' },
      ]
    }
  ];

  mobileNavItems: NavItem[] = this.topGroups.map(g => ({
    label: g.label,
    icon: g.icon ?? g.items[0].icon,
    route: g.items[0].route,
  }));

}
