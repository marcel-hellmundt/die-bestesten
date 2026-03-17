import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../auth/auth.service';

interface NavItem {
  label: string;
  icon: string;
  route: string;
}

interface NavGroup {
  label: string;
  items: NavItem[];
}

@Component({
  selector: 'app-nav',
  standalone: false,
  templateUrl: './nav.component.html',
  styleUrl: './nav.component.scss'
})
export class NavComponent {
  private auth   = inject(AuthService);
  private router = inject(Router);

  managerName = this.auth.getManagerName();

  topGroups: NavGroup[] = [
    {
      label: 'Spiel',
      items: [
        { label: 'Liga',          icon: 'liga',          route: '/app/liga' },
        { label: 'Team',          icon: 'team',          route: '/app/team' },
        { label: 'Transfermarkt', icon: 'transfermarkt', route: '/app/transfermarkt' },
      ]
    }
  ];

  bottomGroups: NavGroup[] = [
    {
      label: 'Sonstiges',
      items: [
        { label: 'Settings',        icon: 'settings', route: '/app/settings' },
        { label: 'Data Management', icon: 'data',     route: '/app/data' },
      ]
    }
  ];

  logout(): void {
    this.auth.logout();
    this.router.navigate(['/login']);
  }
}
