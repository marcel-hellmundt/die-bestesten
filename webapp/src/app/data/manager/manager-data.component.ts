import { Component, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { ROLE_LABEL, ROLE_ORDER } from '../../core/constants';

@Component({
  selector: 'app-data-manager',
  standalone: false,
  templateUrl: './manager-data.component.html',
  styleUrl: './manager-data.component.scss',
})
export class ManagerDataComponent {
  private api   = inject(ApiService);
  private auth  = inject(AuthService);
  cache         = inject(DataCacheService);

  isAdmin = computed(() => this.auth.isAdmin());

  private _managers = signal<any[]>([]);
  loading = signal(true);
  error   = signal<string | null>(null);

  items = computed(() => this._managers());

  searchQuery   = signal('');
  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(
      (m) =>
        m.manager_name.toLowerCase().includes(q) ||
        (m.alias ?? '').toLowerCase().includes(q),
    );
  });

  readonly roleOrder       = ROLE_ORDER;
  readonly roleLabel       = ROLE_LABEL;
  readonly assignableRoles = ['admin', 'maintainer'];

  roleTogglingState = signal<Record<string, boolean>>({});

  constructor() {
    this.api.get<any[]>('manager').subscribe({
      next: (data) => {
        this._managers.set(data ?? []);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Fehler beim Laden');
        this.loading.set(false);
      },
    });
  }

  isRoleToggling(managerId: string, role: string): boolean {
    return this.roleTogglingState()[`${managerId}:${role}`] ?? false;
  }

  toggleRole(manager: any, role: string): void {
    const key = `${manager.id}:${role}`;
    if (this.roleTogglingState()[key]) return;

    const hasRole = (manager.roles ?? []).includes(role);
    this.roleTogglingState.update((s) => ({ ...s, [key]: true }));

    const req = hasRole
      ? this.api.delete<any>(`manager/${manager.id}/roles/${role}`)
      : this.api.post<any>(`manager/${manager.id}/roles`, { role });

    req.subscribe({
      next: () => {
        const newRoles = hasRole
          ? (manager.roles ?? []).filter((r: string) => r !== role)
          : [...(manager.roles ?? []), role];
        this._managers.update((list) =>
          list.map((m) => (m.id === manager.id ? { ...m, roles: newRoles } : m)),
        );
        this.roleTogglingState.update((s) => {
          const n = { ...s };
          delete n[key];
          return n;
        });
      },
      error: () => {
        this.roleTogglingState.update((s) => {
          const n = { ...s };
          delete n[key];
          return n;
        });
      },
    });
  }
}
