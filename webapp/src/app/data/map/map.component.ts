import { Component, ViewChild, computed, effect, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { MapInfoWindow, MapMarker } from '@angular/google-maps';
import { ApiService } from '../../core/api.service';
import { GoogleMapsLoaderService } from '../../core/google-maps-loader.service';
import { StadiumClub, StadiumMapEntry } from '../../core/models/stadium.model';
import { environment } from '../../../environments/environment';

interface MarkerViewModel {
  stadium: StadiumMapEntry;
  club: StadiumClub;
  position: google.maps.LatLngLiteral;
  icon: google.maps.Icon;
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
  selector: 'app-data-map',
  standalone: false,
  templateUrl: './map.component.html',
  styleUrl: './map.component.scss'
})
export class MapDataComponent {
  private api = inject(ApiService);
  private mapsLoader = inject(GoogleMapsLoaderService);

  @ViewChild(MapInfoWindow) infoWindow!: MapInfoWindow;

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

  // Only clubs that currently have a stadium are shown — the marker icon is the club
  // logo (or a placeholder), positioned at the stadium's coordinates.
  markers = computed<MarkerViewModel[]>(() => {
    const dims = this.logoDims();
    return this.stadiums()
      .filter((s): s is StadiumMapEntry & { club: StadiumClub } => s.lat != null && s.lng != null && s.club !== null)
      .map(s => ({
        stadium: s,
        club: s.club,
        position: { lat: s.lat as number, lng: s.lng as number },
        icon: this.clubIcon(s.club, dims[this.clubLogoUrl(s.club)]),
      }));
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
  private requestedLogos = new Set<string>();

  private preloadLogo(url: string): void {
    if (this.requestedLogos.has(url)) return;
    this.requestedLogos.add(url);
    const img = new Image();
    img.onload = () => this.logoDims.update(m => ({ ...m, [url]: { w: img.naturalWidth, h: img.naturalHeight } }));
    img.onerror = () => this.logoDims.update(m => ({ ...m, [url]: { w: 1, h: 1 } }));
    img.src = url;
  }

  private clubIcon(club: StadiumClub, dims?: { w: number; h: number }): google.maps.Icon {
    const box = this.logoBox;
    const { w, h } = dims ? this.fitInBox(dims.w, dims.h, box) : { w: box, h: box };
    return {
      url: this.clubLogoUrl(club),
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

  openInfo(marker: MapMarker, stadium: StadiumMapEntry): void {
    this.selected.set(stadium);
    this.infoWindow.open(marker);
  }

  constructor() {
    this.mapsLoader.load();

    effect(() => {
      for (const s of this.stadiums()) {
        if (s.club) this.preloadLogo(this.clubLogoUrl(s.club));
      }
    });
  }
}
