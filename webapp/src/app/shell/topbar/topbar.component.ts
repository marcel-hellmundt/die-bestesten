import { Component, inject, signal, computed, OnDestroy } from '@angular/core';
import { Router } from '@angular/router';
import { Subject, Subscription } from 'rxjs';
import { debounceTime, distinctUntilChanged, switchMap, catchError, of } from 'rxjs';
import { AuthService } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { ApiService } from '../../core/api.service';

interface SearchResults {
  players:  any[];
  clubs:    any[];
  managers: any[];
  teams:    any[];
}

@Component({
  selector: 'app-topbar',
  standalone: false,
  templateUrl: './topbar.component.html',
  styleUrl: './topbar.component.scss'
})
export class TopbarComponent implements OnDestroy {
  private auth   = inject(AuthService);
  private router = inject(Router);
  private cache  = inject(DataCacheService);
  private api    = inject(ApiService);

  isDropdownOpen  = signal(false);
  avatarImgFailed = signal(false);

  searchQuery   = signal('');
  searchResults = signal<SearchResults | null>(null);
  searchLoading = signal(false);
  isSearchOpen  = signal(false);

  managerName        = computed(() => this.auth.getManagerName() ?? '');
  readonly roleOrder = ['admin', 'maintainer', 'manager'];
  readonly roleLabel: Record<string, string> = { admin: 'Kernel-Kapitän', maintainer: 'Daten-Fee', manager: 'Manager' };

  sortedRoles = computed(() => {
    const roles = ['manager', ...this.auth.getRoles()];
    return [...roles].sort((a, b) => this.roleOrder.indexOf(a) - this.roleOrder.indexOf(b));
  });
  isMaintainer = computed(() => this.auth.isMaintainer());
  avatarUrl    = computed(() => this.cache.managerPhotoUrl(this.auth.getManagerId()));
  initials     = computed(() => {
    const name = this.managerName();
    return name
      ? name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
      : '?';
  });

  failedImageIds = signal<Set<string>>(new Set());

  onImageError(id: string): void {
    this.failedImageIds.update(s => new Set([...s, id]));
  }

  hasResults = computed(() => {
    const r = this.searchResults();
    if (!r) return false;
    return r.players.length + r.clubs.length + r.managers.length + r.teams.length > 0;
  });

  private searchSubject = new Subject<string>();
  private searchSub: Subscription;

  constructor() {
    this.searchSub = this.searchSubject.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      switchMap(q => {
        if (q.length < 3) {
          this.searchResults.set(null);
          this.searchLoading.set(false);
          return of(null);
        }
        this.searchLoading.set(true);
        return this.api.get<SearchResults>(`search?q=${encodeURIComponent(q)}`).pipe(
          catchError(() => of(null))
        );
      })
    ).subscribe(results => {
      this.searchLoading.set(false);
      this.searchResults.set(results);
    });
  }

  ngOnDestroy(): void {
    this.searchSub.unsubscribe();
  }

  onSearchInput(event: Event): void {
    const q = (event.target as HTMLInputElement).value;
    this.searchQuery.set(q);
    if (q.length >= 3) {
      this.searchLoading.set(true);
    } else {
      this.searchResults.set(null);
      this.searchLoading.set(false);
    }
    this.searchSubject.next(q);
  }

  onSearchFocus(): void {
    this.isSearchOpen.set(true);
  }

  closeSearch(): void {
    this.isSearchOpen.set(false);
  }

  navigateToResult(path: string[]): void {
    this.closeSearch();
    this.router.navigate(path);
  }

  playerPhotoUrl(p: any): string | null {
    if (!p.photo_uploaded || !p.season_id) return null;
    return `https://img.die-bestesten.de/img/player/${p.season_id}/${p.id}.png`;
  }

  clubLogoUrl(club: any): string {
    return `https://img.die-bestesten.de/img/club/${club.id}.png`;
  }

  teamPhotoUrl(t: any): string {
    return `https://img.die-bestesten.de/img/team/${t.season_id}/${t.id}.png`;
  }

  managerPhotoUrl(m: any): string {
    return `https://img.die-bestesten.de/img/manager/${m.id}.jpg`;
  }

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
