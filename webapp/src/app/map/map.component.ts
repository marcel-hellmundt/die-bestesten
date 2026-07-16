import { Component, computed, effect, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../core/api.service';
import { DataCacheService } from '../core/data-cache.service';
import { GoogleMapsLoaderService } from '../core/google-maps-loader.service';
import { StadiumClub, StadiumMapEntry } from '../core/models/stadium.model';
import { environment } from '../../environments/environment';

interface MarkerViewModel {
  stadium: StadiumMapEntry;
  club: StadiumClub;
  position: google.maps.LatLngLiteral;
  icon: google.maps.Icon;
  zIndex: number;
}

// Light custom style — labels/POIs mostly stripped so the club logo markers stand out.
const MAP_STYLE: google.maps.MapTypeStyle[] = [
  { featureType: 'all', elementType: 'labels.text', stylers: [{ visibility: 'off' }] },
  { featureType: 'administrative', elementType: 'all', stylers: [{ visibility: 'off' }] },
  { featureType: 'administrative.country', elementType: 'geometry.stroke', stylers: [{ visibility: 'on' }, { color: '#d0d0d0' }] },
  { featureType: 'landscape', elementType: 'all', stylers: [{ color: '#e5e8e7' }, { visibility: 'off' }] },
  { featureType: 'landscape.man_made', elementType: 'geometry.fill', stylers: [{ color: '#ffffff' }, { visibility: 'on' }] },
  { featureType: 'landscape.natural', elementType: 'geometry.fill', stylers: [{ color: '#f5f5f2' }, { visibility: 'on' }] },
  { featureType: 'poi', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.attraction', elementType: 'all', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.business', elementType: 'all', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.government', elementType: 'geometry', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.medical', elementType: 'all', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.park', elementType: 'all', stylers: [{ color: '#91b65d' }, { gamma: 1.51 }] },
  { featureType: 'poi.park', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.place_of_worship', elementType: 'all', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.school', elementType: 'all', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.sports_complex', elementType: 'all', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.sports_complex', elementType: 'geometry', stylers: [{ color: '#c7c7c7' }, { visibility: 'off' }] },
  { featureType: 'road', elementType: 'all', stylers: [{ color: '#ffffff' }] },
  { featureType: 'road', elementType: 'labels', stylers: [{ visibility: 'off' }] },
  { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#ffffff' }, { visibility: 'simplified' }] },
  { featureType: 'road.highway', elementType: 'labels.icon', stylers: [{ color: '#ffffff' }, { visibility: 'off' }] },
  { featureType: 'road.arterial', elementType: 'all', stylers: [{ visibility: 'simplified' }, { color: '#ffffff' }] },
  { featureType: 'road.arterial', elementType: 'geometry', stylers: [{ visibility: 'simplified' }] },
  { featureType: 'road.local', elementType: 'all', stylers: [{ color: '#ffffff' }, { visibility: 'simplified' }] },
  { featureType: 'road.local', elementType: 'geometry', stylers: [{ visibility: 'on' }] },
  { featureType: 'transit', elementType: 'all', stylers: [{ visibility: 'off' }] },
  { featureType: 'water', elementType: 'all', stylers: [{ color: '#a0d3d3' }] },
];

@Component({
  selector: 'app-map',
  standalone: false,
  templateUrl: './map.component.html',
  styleUrl: './map.component.scss'
})
export class MapComponent {
  private api = inject(ApiService);
  private cache = inject(DataCacheService);
  private mapsLoader = inject(GoogleMapsLoaderService);

  mapsReady = this.mapsLoader.ready;

  private state = toSignal(
    this.api.get<any[]>('stadium').pipe(
      map(data => ({ data: data.map(StadiumMapEntry.from), loading: false, error: null as string | null })),
      startWith({ data: [] as StadiumMapEntry[], loading: true, error: null as string | null }),
      catchError(() => of({ data: [] as StadiumMapEntry[], loading: false, error: 'Fehler beim Laden' }))
    )
  );

  stadiums = computed(() => this.state()?.data ?? []);
  loading  = computed(() => this.state()?.loading ?? true);
  error    = computed(() => this.state()?.error ?? null);

  // seasons() is DESC by start_date → [0] = current, [1] = previous — same convention used
  // elsewhere (club.component.ts, ratings.component.ts) for the stacking-order tiebreakers below.
  private currentSeasonId = computed(() => this.cache.seasons()[0]?.id ?? null);
  private prevSeasonId    = computed(() => this.cache.seasons()[1]?.id ?? null);

  private currentSeasonEntries = toSignal(
    toObservable(this.currentSeasonId).pipe(
      switchMap(id => (id ? this.api.get<any[]>(`club_in_season?season_id=${id}`) : of([] as any[]))),
      catchError(() => of([] as any[])),
    ),
    { initialValue: [] as any[] },
  );

  private prevSeasonEntries = toSignal(
    toObservable(this.prevSeasonId).pipe(
      switchMap(id => (id ? this.api.get<any[]>(`club_in_season?season_id=${id}`) : of([] as any[]))),
      catchError(() => of([] as any[])),
    ),
    { initialValue: [] as any[] },
  );

  // club_id -> division level (1 = top division) for a given set of club_in_season entries
  private levelByClub(entries: any[]): Map<string, number> {
    const levelByDivision = new Map(this.cache.divisions().map(d => [d.id, d.level]));
    const map = new Map<string, number>();
    for (const e of entries) {
      const level = e.division_id ? levelByDivision.get(e.division_id) : undefined;
      if (level !== undefined) map.set(e.club_id, level);
    }
    return map;
  }

  private clubLevel     = computed(() => this.levelByClub(this.currentSeasonEntries()));
  private clubPrevLevel = computed(() => this.levelByClub(this.prevSeasonEntries()));

  // club_id -> previous season's table position
  private clubPrevPosition = computed(() => {
    const map = new Map<string, number>();
    for (const e of this.prevSeasonEntries()) {
      if (e.position != null) map.set(e.club_id, e.position as number);
    }
    return map;
  });

  // club_id -> current division_id
  private clubDivisionId = computed(() => {
    const map = new Map<string, string>();
    for (const e of this.currentSeasonEntries()) {
      if (e.division_id) map.set(e.club_id, e.division_id);
    }
    return map;
  });

  divisions = computed(() => [...this.cache.divisions()].sort((a, b) => a.level - b.level));

  // Mobile: the division buttons collapse behind this toggle instead of showing all at once.
  filtersMenuOpen = signal(false);

  // Division filter buttons — every division starts active; toggling one hides its clubs'
  // markers. Lazily initialized once the division list has actually loaded, either from a
  // stored selection (persisted across sessions) or "all active" as the default.
  private readonly DIVISION_FILTER_STORAGE_KEY = 'map-active-divisions';
  private activeDivisionIds = signal<Set<string>>(new Set());
  private divisionsInitialized = false;

  private loadStoredActiveDivisions(): string[] | null {
    try {
      const raw = localStorage.getItem(this.DIVISION_FILTER_STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  isDivisionActive(divisionId: string): boolean {
    return this.activeDivisionIds().has(divisionId);
  }

  toggleDivision(divisionId: string): void {
    this.activeDivisionIds.update(set => {
      const next = new Set(set);
      if (next.has(divisionId)) next.delete(divisionId);
      else next.add(divisionId);
      return next;
    });
  }

  // Only clubs that currently have a stadium are shown — the marker icon is the club logo
  // (or a placeholder), positioned at the stadium's coordinates. Stacking order (zIndex) is
  // explicit rather than left to Maps' default screen-position stacking: current division
  // (lower level number = higher stack) comes first, then previous season's division (a 5th
  // place in the top flight outranks a 1st place one division down — comparing raw table
  // position across divisions would get that backwards), and finally the raw previous-season
  // position as the last tiebreaker.
  markers = computed<MarkerViewModel[]>(() => {
    const dims     = this.logoDims();
    const level    = this.clubLevel();
    const prevLvl  = this.clubPrevLevel();
    const prevPos  = this.clubPrevPosition();
    const divisionByClub = this.clubDivisionId();
    const active   = this.activeDivisionIds();
    const visited  = this.visitedStadiumIds();

    const entries = this.stadiums()
      .filter((s): s is StadiumMapEntry & { club: StadiumClub } => s.lat != null && s.lng != null && s.club !== null)
      .filter(s => {
        const divisionId = divisionByClub.get(s.club.id);
        return divisionId != null && active.has(divisionId);
      })
      .map(s => ({
        stadium: s,
        club: s.club,
        position: { lat: s.lat as number, lng: s.lng as number },
        icon: this.clubIcon(s.club, visited.has(s.id), dims[this.clubLogoUrl(s.club)]),
        level: level.get(s.club.id) ?? Number.MAX_SAFE_INTEGER,
        prevLevel: prevLvl.get(s.club.id) ?? Number.MAX_SAFE_INTEGER,
        prevPos: prevPos.get(s.club.id) ?? Number.MAX_SAFE_INTEGER,
      }));

    entries.sort((a, b) => a.level - b.level || a.prevLevel - b.prevLevel || a.prevPos - b.prevPos);

    return entries.map((e, i) => ({
      stadium: e.stadium,
      club: e.club,
      position: e.position,
      icon: e.icon,
      zIndex: entries.length - i,
    }));
  });

  // Progress bar counts — scoped to the currently visible (filtered) markers, not all stadiums.
  visibleCount = computed(() => this.markers().length);
  visitedCount = computed(() => {
    const visited = this.visitedStadiumIds();
    return this.markers().filter(m => visited.has(m.stadium.id)).length;
  });
  visitedPercent = computed(() => {
    const total = this.visibleCount();
    return total ? Math.round((this.visitedCount() / total) * 100) : 0;
  });

  clubLogoUrl(club: StadiumClub): string {
    return club.logo_uploaded
      ? `${environment.imageApiUrl}/club/${club.id}.png`
      : 'img/placeholders/club.png';
  }

  private readonly logoBox = 36;

  // Google Maps' scaledSize always stretches an icon to the exact box, unlike CSS
  // object-fit: contain. So the natural size of every logo is preloaded once and used
  // to scale proportionally within logoBox, keeping narrow/non-square crests undistorted.
  private logoDims = signal<Record<string, { w: number; h: number }>>({});
  // Grayscale+dimmed data-URL variant, best-effort — see preloadLogo() for why this is a
  // separate load from the one above.
  private logoGrey = signal<Record<string, string>>({});
  private requestedLogos = new Set<string>();

  private preloadLogo(url: string): void {
    if (this.requestedLogos.has(url)) return;
    this.requestedLogos.add(url);

    // Plain load for natural dimensions. naturalWidth/naturalHeight are always readable
    // regardless of CORS, so aspect-ratio fitting must not depend on crossOrigin — otherwise
    // a missing Access-Control-Allow-Origin on the asset server fails this load outright
    // (onerror) and every icon falls back to a stretched square.
    const img = new Image();
    img.onload = () => this.logoDims.update(m => ({ ...m, [url]: { w: img.naturalWidth, h: img.naturalHeight } }));
    img.onerror = () => this.logoDims.update(m => ({ ...m, [url]: { w: 1, h: 1 } }));
    img.src = url;

    // Separate CORS-mode load, used only to attempt a grayscale canvas conversion for
    // unvisited stadiums. If the asset server doesn't send the CORS header, this just fails
    // quietly and the color icon is used instead — it never touches the load above.
    // Cache-busted with a query param: these logos are also loaded via plain <img> tags all
    // over the app (club lists, search, topbar, ...), and on Safari/mobile WebKit a CORS-mode
    // request for a URL already cached from a non-CORS <img> fetch can silently return that
    // cached (non-CORS) response, tainting the canvas despite crossOrigin being set. A
    // distinct URL forces a real CORS-negotiated fetch.
    const corsImg = new Image();
    corsImg.crossOrigin = 'anonymous';
    corsImg.onload = () => {
      const grey = this.toGreyscaleUrl(corsImg);
      if (grey) this.logoGrey.update(m => ({ ...m, [url]: grey }));
    };
    corsImg.src = url + (url.includes('?') ? '&' : '?') + 'cors=1';
  }

  private toGreyscaleUrl(img: HTMLImageElement): string | null {
    const canvas = document.createElement('canvas');
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    // Manual luminance grayscale + halved alpha via getImageData/putImageData rather than
    // ctx.filter — filter support on canvas 2d contexts is still inconsistent on older/mobile
    // WebKit, where it can silently no-op and leave the icon in full color.
    ctx.drawImage(img, 0, 0);
    try {
      const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const data = imageData.data;
      for (let i = 0; i < data.length; i += 4) {
        const grey = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
        data[i] = data[i + 1] = data[i + 2] = grey;
        data[i + 3] = data[i + 3] * 0.5;
      }
      ctx.putImageData(imageData, 0, 0);
      return canvas.toDataURL('image/png');
    } catch {
      return null; // cross-origin canvas got tainted — fall back to the color icon
    }
  }

  private clubIcon(club: StadiumClub, visited: boolean, dims?: { w: number; h: number }): google.maps.Icon {
    const box = this.logoBox;
    const { w, h } = dims ? this.fitInBox(dims.w, dims.h, box) : { w: box, h: box };
    const url = this.clubLogoUrl(club);
    return {
      url: visited ? url : (this.logoGrey()[url] ?? url),
      // Plain {width,height}/{x,y} objects work at runtime — avoids depending on
      // google.maps.Size/Point classes, which may not be loaded yet at this point.
      scaledSize: { width: w, height: h } as google.maps.Size,
      anchor: { x: w / 2, y: h / 2 } as google.maps.Point,
    };
  }

  private fitInBox(naturalW: number, naturalH: number, box: number): { w: number; h: number } {
    const scale = Math.min(box / naturalW, box / naturalH);
    return { w: Math.round(naturalW * scale), h: Math.round(naturalH * scale) };
  }

  center: google.maps.LatLngLiteral = { lat: 51.1657, lng: 10.4515 }; // geografische Mitte Deutschlands
  zoom = 6;
  mapOptions: google.maps.MapOptions = {
    streetViewControl: false,
    fullscreenControl: false,
    mapTypeControl: false,
    styles: MAP_STYLE,
  };

  selected = signal<StadiumMapEntry | null>(null);

  selectStadium(stadium: StadiumMapEntry): void {
    this.selected.set(stadium);
  }

  closeInfo(): void {
    this.selected.set(null);
  }

  // Stadiums the logged-in manager has marked as visited.
  private visitedStadiumIds = signal<Set<string>>(new Set());

  isVisited(stadiumId: string): boolean {
    return this.visitedStadiumIds().has(stadiumId);
  }

  visitorText(stadium: StadiumMapEntry): string {
    const iVisited = this.isVisited(stadium.id);
    const others = stadium.other_visitors.length;

    if (!iVisited && others === 0) return 'Hier war noch niemand';
    if (iVisited && others === 0) return 'Außer dir war noch niemand hier';
    if (!iVisited && others === 1) return '1 anderer Manager war hier';
    if (!iVisited) return `${others} andere Manager waren hier`;
    if (others === 1) return '1 anderer Manager war neben dir hier';
    return `${others} andere Manager waren neben dir hier`;
  }

  // Small avatar row shown next to visitorText() — falls back to initials on load error,
  // same pattern as liga-teams.component.ts's manager avatars.
  private managerPhotoErrors = new Set<string>();

  managerPhotoFailed(managerId: string): boolean {
    return this.managerPhotoErrors.has(managerId);
  }

  onManagerPhotoError(managerId: string): void {
    this.managerPhotoErrors.add(managerId);
  }

  managerPhotoUrl(managerId: string): string {
    return `${environment.imageApiUrl}/manager/${managerId}.jpg`;
  }

  toggleVisited(stadiumId: string): void {
    const wasVisited = this.isVisited(stadiumId);

    // Optimistic update, rolled back if the request fails.
    this.visitedStadiumIds.update(set => {
      const next = new Set(set);
      if (wasVisited) next.delete(stadiumId);
      else next.add(stadiumId);
      return next;
    });

    const request = wasVisited
      ? this.api.delete<{ status: boolean }>(`manager_stadium/${stadiumId}`)
      : this.api.post<{ status: boolean }>('manager_stadium', { stadium_id: stadiumId });

    request.subscribe({
      error: () => {
        this.visitedStadiumIds.update(set => {
          const next = new Set(set);
          if (wasVisited) next.add(stadiumId);
          else next.delete(stadiumId);
          return next;
        });
      },
    });
  }

  constructor() {
    this.mapsLoader.load();
    this.cache.ensureSeasons();
    this.cache.ensureDivisions();

    this.api.get<string[]>('manager_stadium').pipe(catchError(() => of([]))).subscribe(ids => {
      this.visitedStadiumIds.set(new Set(ids));
    });

    effect(() => {
      for (const s of this.stadiums()) {
        if (s.club) this.preloadLogo(this.clubLogoUrl(s.club));
      }
    });

    effect(() => {
      const divs = this.cache.divisions();
      if (divs.length && !this.divisionsInitialized) {
        this.divisionsInitialized = true;
        const stored = this.loadStoredActiveDivisions();
        if (stored) {
          const validIds = new Set(divs.map(d => d.id));
          this.activeDivisionIds.set(new Set(stored.filter(id => validIds.has(id))));
        } else {
          // No stored selection yet — default to the top two divisions (1. + 2. Liga).
          this.activeDivisionIds.set(new Set(divs.filter(d => d.level <= 2).map(d => d.id)));
        }
      }
    });

    // Persist the division filter selection across sessions, once initialized.
    effect(() => {
      const active = this.activeDivisionIds();
      if (!this.divisionsInitialized) return;
      localStorage.setItem(this.DIVISION_FILTER_STORAGE_KEY, JSON.stringify([...active]));
    });
  }
}
