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

  roleTogglingState    = signal<Record<string, boolean>>({});
  allLeagues           = signal<any[]>([]);
  allLeaguesLoaded     = signal(false);
  activeInvitePopup    = signal<string | null>(null);
  membershipLoading    = signal<Record<string, boolean>>({});

  private exactDateTime(d: Date): string {
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' })
      + ' ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
  }

  formatLastActivity(dateStr: string | null): string {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    const diffMin = Math.floor((Date.now() - d.getTime()) / 60000);

    if (diffMin < 1) return 'gerade eben';
    if (diffMin < 60) return `vor ${diffMin} Minute${diffMin === 1 ? '' : 'n'}`;

    const diffHours = Math.floor(diffMin / 60);
    if (diffHours < 24) return `vor ${diffHours} Stunde${diffHours === 1 ? '' : 'n'}`;

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays <= 3) return `vor ${diffDays} Tag${diffDays === 1 ? '' : 'en'}`;

    return this.exactDateTime(d);
  }

  lastActivityTitle(dateStr: string | null): string {
    if (!dateStr) return '';
    return this.exactDateTime(new Date(dateStr));
  }

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

  availableLeaguesFor(manager: any): any[] {
    const managerLeagueIds = new Set((manager.leagues ?? []).map((l: any) => l.id));
    return this.allLeagues().filter(l => !managerLeagueIds.has(l.id));
  }

  openInvitePopup(managerId: string): void {
    if (this.activeInvitePopup() === managerId) {
      this.activeInvitePopup.set(null);
      return;
    }
    this.activeInvitePopup.set(managerId);
    if (!this.allLeaguesLoaded()) {
      this.api.get<any[]>('league').subscribe({
        next: (data) => { this.allLeagues.set(data ?? []); this.allLeaguesLoaded.set(true); },
        error: () => {},
      });
    }
  }

  isMembershipLoading(managerId: string, leagueId: string): boolean {
    return this.membershipLoading()[`${managerId}:${leagueId}`] ?? false;
  }

  inviteToLeague(manager: any, leagueId: string): void {
    const key = `${manager.id}:${leagueId}`;
    if (this.membershipLoading()[key]) return;
    const leagueName = this.allLeagues().find(l => l.id === leagueId)?.name ?? leagueId;
    this.membershipLoading.update(s => ({ ...s, [key]: true }));
    this.api.post<any>(`league/${leagueId}/invite`, { manager_id: manager.id }).subscribe({
      next: () => {
        this._managers.update(list => list.map(m =>
          m.id === manager.id
            ? { ...m, leagues: [...(m.leagues ?? []), { id: leagueId, name: leagueName, status: 'invited' }] }
            : m
        ));
        this.membershipLoading.update(s => { const n = { ...s }; delete n[key]; return n; });
        this.activeInvitePopup.set(null);
      },
      error: () => this.membershipLoading.update(s => { const n = { ...s }; delete n[key]; return n; }),
    });
  }

  approveMembership(manager: any, leagueId: string): void {
    const key = `${manager.id}:${leagueId}`;
    if (this.membershipLoading()[key]) return;
    this.membershipLoading.update(s => ({ ...s, [key]: true }));
    this.api.post<any>(`league/${leagueId}/approve`, { manager_id: manager.id }).subscribe({
      next: () => {
        this._managers.update(list => list.map(m =>
          m.id === manager.id
            ? { ...m, leagues: (m.leagues ?? []).map((l: any) => l.id === leagueId ? { ...l, status: 'active' } : l) }
            : m
        ));
        this.membershipLoading.update(s => { const n = { ...s }; delete n[key]; return n; });
      },
      error: () => this.membershipLoading.update(s => { const n = { ...s }; delete n[key]; return n; }),
    });
  }

  denyMembership(manager: any, leagueId: string): void {
    const key = `${manager.id}:${leagueId}`;
    if (this.membershipLoading()[key]) return;
    this.membershipLoading.update(s => ({ ...s, [key]: true }));
    this.api.post<any>(`league/${leagueId}/deny`, { manager_id: manager.id }).subscribe({
      next: () => {
        this._managers.update(list => list.map(m =>
          m.id === manager.id
            ? { ...m, leagues: (m.leagues ?? []).filter((l: any) => l.id !== leagueId) }
            : m
        ));
        this.membershipLoading.update(s => { const n = { ...s }; delete n[key]; return n; });
      },
      error: () => this.membershipLoading.update(s => { const n = { ...s }; delete n[key]; return n; }),
    });
  }
}
