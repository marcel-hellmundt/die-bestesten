import { Component, inject, signal, computed } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';

@Component({
  selector: 'app-topbar',
  standalone: false,
  templateUrl: './topbar.component.html',
  styleUrl: './topbar.component.scss'
})
export class TopbarComponent {
  private auth   = inject(AuthService);
  private router = inject(Router);
  private cache  = inject(DataCacheService);

  isDropdownOpen  = signal(false);
  avatarImgFailed = signal(false);

  managerName        = computed(() => this.auth.getManagerName() ?? '');
  readonly roleOrder = ['admin', 'maintainer', 'manager'];
  readonly roleLabel: Record<string, string> = { admin: 'Kernel-Kapitän', maintainer: 'Daten-Fee', manager: 'Manager' };

  sortedRoles = computed(() => {
    const r = this.auth.getRoles();
    const roles = r.length ? r : ['manager'];
    return [...roles].sort((a, b) => this.roleOrder.indexOf(a) - this.roleOrder.indexOf(b));
  });
  isMaintainer        = computed(() => this.auth.isMaintainer());
  avatarUrl   = computed(() => this.cache.managerPhotoUrl(this.auth.getManagerId()));
  initials    = computed(() => {
    const name = this.managerName();
    return name
      ? name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
      : '?';
  });

  onAvatarError(): void {
    this.avatarImgFailed.set(true);
  }

  toggleDropdown(event: Event): void {
    event.stopPropagation();
    this.isDropdownOpen.update(v => !v);
  }

  closeDropdown(): void {
    this.isDropdownOpen.set(false);
  }

  navigateTo(route: string): void {
    this.closeDropdown();
    this.router.navigate([route]);
  }

  logout(): void {
    this.auth.logout();
    this.router.navigate(['/login']);
  }
}
