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
        { label: 'Spieltag',      icon: 'spieltag',      route: '/app/spieltag' },
        { label: 'Tabelle',       icon: 'tabelle',       route: '/app/tabelle' },
        { label: 'Statistiken',   icon: 'statistiken',   route: '/app/liga-statistiken' },
        { label: 'Ewige Tabelle', icon: 'ewige-tabelle', route: '/app/ewige-tabelle' },
      ]
    },
    {
      label: 'Team',
      icon: 'kader',
      items: [
        { label: 'Kader',       icon: 'kader',      route: '/app/kader' },
        { label: 'Aufstellung', icon: 'aufstellung', route: '/app/aufstellung' },
        { label: 'Finanzen',    icon: 'finanzen',    route: '/app/finanzen' },
        { label: 'Statistiken', icon: 'statistiken', route: '/app/team-statistiken' },
      ]
    },
    {
      label: 'Markt',
      icon: 'transferphasen',
      items: [
        { label: 'Spieler',        icon: 'spieler',        route: '/app/spieler' },
        { label: 'Transferphasen', icon: 'transferphasen', route: '/app/transferphasen' },
        { label: 'Gebote',         icon: 'gebote',         route: '/app/gebote' },
      ]
    },
  ];

  bottomGroups: NavGroup[] = [
    {
      label: '',
      items: [
        { label: 'Einstellungen',   icon: 'settings', route: '/app/settings' },
        { label: 'Data Management', icon: 'data',     route: '/app/data' },
      ]
    }
  ];

  mobileNavItems: NavItem[] = this.topGroups.map(g => ({
    label: g.label,
    icon: g.icon ?? g.items[0].icon,
    route: g.items[0].route,
  }));

}
