import { Component, inject, signal, computed, OnDestroy, ViewChild, ElementRef, HostListener } from '@angular/core';
import { Router, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs';
import { Subject, Subscription } from 'rxjs';
import { debounceTime, distinctUntilChanged, switchMap, catchError, of } from 'rxjs';
import { AuthService, League } from '../../auth/auth.service';
import { DataCacheService } from '../../core/data-cache.service';
import { ApiService } from '../../core/api.service';
import { NotificationService } from '../../core/notification.service';
import { ROLE_LABEL, ROLE_ORDER } from '../../core/constants';

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
  notifService   = inject(NotificationService);

  isDropdownOpen       = signal(false);
  isLeagueDropdownOpen = signal(false);
  avatarImgFailed      = signal(false);
  currentUrl           = signal(this.router.url);

  leagues          = signal<League[]>([]);
  leagueSwitching  = signal(false);
  allLeagues       = signal<any[]>([]);
  allLeaguesLoaded = signal(false);
  showJoinSection  = signal(false);
  leagueActionState = signal<Record<string, 'loading' | 'done' | 'error'>>({});

  activeLeagues    = computed(() => this.leagues().filter(l => !l.status || l.status === 'active'));
  invitedLeagues   = computed(() => this.leagues().filter(l => l.status === 'invited'));
  showLeagueSwitcher = computed(() => this.activeLeagues().length > 1 || this.invitedLeagues().length > 0);
  availableLeagues = computed(() => {
    const myIds = new Set(this.leagues().map(l => l.id));
    return this.allLeagues().filter(l => !myIds.has(l.id) && l.visibility !== 'private');
  });

  birthdayNames = signal<string[]>([]);
  birthdayEmoji = signal('');
  birthdayLabel = computed(() => {
    const names = this.birthdayNames();
    if (!names.length) return null;
    if (names.length === 1) return `Heute hat ${names[0]} Geburtstag`;
    if (names.length === 2) return `Heute haben ${names[0]} und ${names[1]} Geburtstag`;
    return `Heute haben ${names.length} Manager Geburtstag`;
  });

  private readonly birthdayEmojis = ['🎂', '🥳', '🎉', '🍾', '🎊', '🎈'];

  searchQuery   = signal('');
  searchResults = signal<SearchResults | null>(null);
  searchLoading = signal(false);
  isSearchOpen  = signal(false);
  // Bleibt beim Schließen noch true, bis die 0.25s-Breiten-Transition der Suchbox durchgelaufen
  // ist (siehe onSearchTransitionEnd) — .topbar-search muss so lange position:absolute behalten
  // (siehe SCSS &--has-league), sonst ist sie beim Zuklappen sofort wieder normales Flex-Element
  // und zieht .topbar-league live mit, während sie sich zurück auf Icon-Breite verkleinert.
  isSearchClosing = signal(false);
  // "Aktiv" für die Layout-Zwecke (Klassen/Breite) — schließt die Ausklingphase mit ein, im
  // Unterschied zu isSearchOpen() (steuert Fokus/Ergebnisse/Sichtbarkeit des Inputs selbst).
  isSearchExpanded = computed(() => this.isSearchOpen() || this.isSearchClosing());

  managerName        = computed(() => this.auth.getManagerName() ?? '');
  managerId          = computed(() => this.auth.getManagerId());
  activeLeagueId     = computed(() => this.auth.getLeagueId());
  activeLeagueName   = computed(() => {
    const id = this.activeLeagueId();
    if (!id) return null;
    return this.leagues().find(l => l.id === id)?.name ?? this.cache.leagueName() ?? null;
  });
  readonly roleOrder = ROLE_ORDER;
  readonly roleLabel = ROLE_LABEL;

  sortedRoles = computed(() => {
    const roles = ['manager', ...this.auth.getRoles()];
    return [...roles].sort((a, b) => this.roleOrder.indexOf(a) - this.roleOrder.indexOf(b));
  });
  isMaintainer  = computed(() => this.auth.isMaintainer());
  isContributor = computed(() => this.auth.isContributor());
  avatarUrl     = computed(() => this.cache.managerPhotoUrl(this.auth.getManagerId()));
  initials     = computed(() => {
    const name = this.managerName();
    return name
      ? name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
      : '?';
  });

  @ViewChild('searchInput') searchInputRef?: ElementRef<HTMLInputElement>;
  @ViewChild('searchContainer') searchContainerRef?: ElementRef<HTMLElement>;
  @ViewChild('leagueEl') leagueRef?: ElementRef<HTMLElement>;
  @ViewChild('userMenuEl') userMenuRef?: ElementRef<HTMLElement>;

  // Mobil, wenn eine Liga-Auswahl existiert: die aufklappende Suche legt sich per
  // position:absolute exakt bis zum rechten Rand von .topbar-league (siehe Template/SCSS) —
  // dieser Wert wird VOR dem Öffnen gemessen, damit .topbar-league dabei nie ihre eigene Breite
  // ändert (sie bliebe sonst über normales Flex-Wachstum mit der Suche gekoppelt).
  searchExpandWidth = signal<number | null>(null);

  private measureSearchExpandWidth(): void {
    const searchEl = this.searchContainerRef?.nativeElement;
    const leagueEl = this.leagueRef?.nativeElement;
    if (!searchEl || !leagueEl) { this.searchExpandWidth.set(null); return; }
    const searchLeft  = searchEl.getBoundingClientRect().left;
    const leagueRight = leagueEl.getBoundingClientRect().right;
    this.searchExpandWidth.set(Math.max(0, leagueRight - searchLeft));
  }

  @HostListener('window:resize')
  onWindowResize(): void {
    if (this.isSearchOpen()) this.measureSearchExpandWidth();
  }

  // Das per searchExpandWidth() gemessene absolute Breiten-Wachstum ist nur auf Mobile nötig
  // (dort legt sich die aufklappende Suche per position:absolute über die Liga-Auswahl, siehe
  // SCSS &--has-league) — auf Desktop bleibt .topbar-search normales Flex-Element mit fester
  // 260px-Breite, ein gesetzter Inline-Width-Style hätte sie dort ungewollt breitgezogen.
  // $mobile-breakpoint aus _variables.scss (768px) lässt sich hier nicht importieren, daher fix verdrahtet.
  isMobileViewport(): boolean {
    return window.matchMedia('(max-width: 768px)').matches;
  }

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
    this.router.events.pipe(filter(e => e instanceof NavigationEnd)).subscribe((e: any) => {
      this.currentUrl.set(e.urlAfterRedirects);
    });

    this.api.get<{ leagues: League[] }>('manager/leagues').subscribe({
      next: data => this.leagues.set(data.leagues ?? []),
      error: ()  => {},
    });

    this.api.get<any[]>('manager/birthdays').subscribe({
      next: data => {
        if (data?.length) {
          this.birthdayNames.set(data.map(m => m.manager_name));
          this.birthdayEmoji.set(this.birthdayEmojis[Math.floor(Math.random() * this.birthdayEmojis.length)]);
        }
      },
      error: () => {},
    });

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

  onSearchContainerClick(): void {
    if (!this.isSearchOpen()) {
      this.measureSearchExpandWidth();
      this.isSearchOpen.set(true);
      this.searchInputRef?.nativeElement.focus();
    }
  }

  onSearchFocus(): void {
    this.measureSearchExpandWidth();
    this.isSearchOpen.set(true);
  }

  closeSearch(): void {
    this.isSearchOpen.set(false);
    this.searchInputRef?.nativeElement.blur();
    if (this.showLeagueSwitcher()) {
      this.isSearchClosing.set(true);
      // Fallback, falls transitionend aus irgendeinem Grund nie feuert (z.B. schnelles
      // Auf/Zu-Klicken unterbricht die Transition) — verhindert, dass isSearchClosing dauerhaft
      // hängen bleibt. Etwas länger als die 0.25s-CSS-Transition.
      window.setTimeout(() => this.isSearchClosing.set(false), 300);
    }
  }

  // Feuert u.a. beim width-Übergang der Suchbox (siehe .topbar-search transition) — erst wenn der
  // beim Schließen fertig durchgelaufen ist, darf .topbar-search wieder normales Flex-Element
  // werden (siehe isSearchExpanded()/isSearchClosing oben).
  onSearchTransitionEnd(event: TransitionEvent): void {
    if (event.propertyName !== 'width') return;
    if (!this.isSearchOpen()) this.isSearchClosing.set(false);
  }

  // Der bestehende Backdrop (Sibling nach .topbar, für User-/Liga-Dropdown) reicht auf Mobile
  // nicht: .topbar selbst hat per sticky-Positionierung ein höheres z-index als der Backdrop und
  // fängt daher jeden Klick innerhalb des Topbar-Streifens (z.B. auf die restliche freie Fläche
  // oder ein anderes Icon) ab, bevor er den Backdrop erreicht — ein Dropdown blieb dann offen,
  // bis man exakt den eigenen Toggle-Button erneut traf oder außerhalb der ganzen Topbar klickte.
  // Ein document-weiter Klick-Listener schließt stattdessen bei jedem Klick außerhalb der
  // jeweiligen Box, unabhängig von Stacking-Kontexten — für Suche, User- und Liga-Dropdown gleichermaßen.
  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    const target = event.target as Node;

    if (this.isSearchOpen() && !this.searchContainerRef?.nativeElement.contains(target)) {
      this.closeSearch();
    }
    if (this.isDropdownOpen() && !this.userMenuRef?.nativeElement.contains(target)) {
      this.closeDropdown();
    }
    if (this.isLeagueDropdownOpen() && !this.leagueRef?.nativeElement.contains(target)) {
      this.closeLeagueDropdown();
    }
  }

  playerPhotoUrl(p: any): string | null {
    if (!p.photo_uploaded || !p.season_id) return null;
    return `https://img.die-bestesten.de/player/${p.season_id}/${p.id}.png`;
  }

  clubLogoUrl(club: any): string {
    return `https://img.die-bestesten.de/club/${club.id}.png`;
  }

  teamPhotoUrl(t: any): string {
    return `https://img.die-bestesten.de/team/${t.season_id}/${t.id}.png`;
  }

  managerPhotoUrl(m: any): string {
    return `https://img.die-bestesten.de/manager/${m.id}.jpg`;
  }

  onAvatarError(): void {
    this.avatarImgFailed.set(true);
  }

  toggleDropdown(event: Event): void {
    event.stopPropagation();
    this.isDropdownOpen.update(v => !v);
    if (this.isDropdownOpen()) this.isLeagueDropdownOpen.set(false);
  }

  closeDropdown(): void {
    this.isDropdownOpen.set(false);
  }

  toggleLeagueDropdown(event: Event): void {
    event.stopPropagation();
    this.isLeagueDropdownOpen.update(v => !v);
    if (this.isLeagueDropdownOpen()) this.isDropdownOpen.set(false);
  }

  closeLeagueDropdown(): void {
    this.isLeagueDropdownOpen.set(false);
  }

  // team/:id und liga/h2h/:id verweisen auf Liga-DB-spezifische IDs (team, h2h_match) — die
  // existieren nach einem Liga-Wechsel nicht mehr unter derselben ID, daher Rückfall auf die
  // nächstmögliche Übersichtsseite. Alle anderen IDs (Spieler, Verein, Manager, Transferfenster, …)
  // sind global und bleiben nach dem Wechsel gültig, dort wird dieselbe URL einfach neu geladen.
  private readonly leagueScopedRouteFallbacks: { pattern: RegExp; fallback: string }[] = [
    { pattern: /^\/team\/[^/]+/, fallback: '/liga/teams' },
    { pattern: /^\/liga\/h2h\/(?!modus$)[^/]+$/, fallback: '/liga/h2h' },
  ];

  private buildLeagueSwitchTargetUrl(): string {
    const current = this.router.url;
    const hit = this.leagueScopedRouteFallbacks.find(({ pattern }) => pattern.test(current));
    return hit ? hit.fallback : current;
  }

  switchLeague(leagueId: string): void {
    if (leagueId === this.activeLeagueId() || this.leagueSwitching()) return;
    this.leagueSwitching.set(true);
    this.closeLeagueDropdown();
    const targetUrl = this.buildLeagueSwitchTargetUrl();
    this.auth.switchLeague(leagueId).subscribe({
      next: () => {
        window.location.href = targetUrl;
      },
      error: () => this.leagueSwitching.set(false),
    });
  }

  loadAllLeagues(): void {
    if (this.allLeaguesLoaded()) return;
    this.api.get<any[]>('league').subscribe({
      next: (data) => { this.allLeagues.set(data ?? []); this.allLeaguesLoaded.set(true); },
      error: () => {},
    });
  }

  toggleJoinSection(): void {
    this.showJoinSection.update(v => !v);
    if (this.showJoinSection()) this.loadAllLeagues();
  }

  declineInvite(leagueId: string): void {
    if (this.leagueActionState()[leagueId] === 'loading') return;
    this.leagueActionState.update(s => ({ ...s, [leagueId]: 'loading' }));
    this.api.post<any>(`league/${leagueId}/decline`, {}).subscribe({
      next: () => {
        this.leagues.update(list => list.filter(l => l.id !== leagueId));
        this.leagueActionState.update(s => { const n = { ...s }; delete n[leagueId]; return n; });
      },
      error: () => this.leagueActionState.update(s => { const n = { ...s }; delete n[leagueId]; return n; }),
    });
  }

  acceptInvite(leagueId: string): void {
    if (this.leagueActionState()[leagueId] === 'loading') return;
    this.leagueActionState.update(s => ({ ...s, [leagueId]: 'loading' }));
    this.api.post<any>(`league/${leagueId}/accept`, {}).subscribe({
      next: () => {
        this.api.get<{ leagues: League[] }>('manager/leagues').subscribe({
          next: (data) => {
            this.leagues.set(data.leagues ?? []);
            this.leagueActionState.update(s => { const n = { ...s }; delete n[leagueId]; return n; });
            this.switchLeague(leagueId);
          },
          error: () => this.leagueActionState.update(s => { const n = { ...s }; delete n[leagueId]; return n; }),
        });
      },
      error: () => this.leagueActionState.update(s => { const n = { ...s }; delete n[leagueId]; return n; }),
    });
  }

  requestJoin(leagueId: string): void {
    if (this.leagueActionState()[leagueId] === 'loading') return;
    this.leagueActionState.update(s => ({ ...s, [leagueId]: 'loading' }));
    const leagueName = this.allLeagues().find(l => l.id === leagueId)?.name ?? leagueId;
    this.api.post<any>(`league/${leagueId}/join`, {}).subscribe({
      next: () => {
        this.allLeagues.update(list => list.filter(l => l.id !== leagueId));
        this.leagues.update(list => [...list, { id: leagueId, name: leagueName, slug: '', status: 'requested' as const }]);
        this.leagueActionState.update(s => { const n = { ...s }; delete n[leagueId]; return n; });
      },
      error: () => this.leagueActionState.update(s => { const n = { ...s }; delete n[leagueId]; return n; }),
    });
  }

  logout(): void {
    this.auth.logout();
    this.router.navigate(['/login']);
  }
}
