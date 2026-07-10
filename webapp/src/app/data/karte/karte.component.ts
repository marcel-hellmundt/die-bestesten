import { Component, ViewChild, computed, inject, signal } from '@angular/core';
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

@Component({
  selector: 'app-data-karte',
  standalone: false,
  templateUrl: './karte.component.html',
  styleUrl: './karte.component.scss'
})
export class KarteDataComponent {
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
  markers = computed<MarkerViewModel[]>(() =>
    this.stadiums()
      .filter((s): s is StadiumMapEntry & { club: StadiumClub } => s.lat != null && s.lng != null && s.club !== null)
      .map(s => ({
        stadium: s,
        club: s.club,
        position: { lat: s.lat as number, lng: s.lng as number },
        icon: this.clubIcon(s.club),
      }))
  );

  clubLogoUrl(club: StadiumClub): string {
    return club.logo_uploaded
      ? `${environment.imageApiUrl}/club/${club.id}.png`
      : 'img/placeholders/club.png';
  }

  private clubIcon(club: StadiumClub): google.maps.Icon {
    return {
      url: this.clubLogoUrl(club),
      // Plain {width,height}/{x,y} objects work at runtime — avoids depending on
      // google.maps.Size/Point classes, which may not be loaded yet at this point.
      scaledSize: { width: 36, height: 36 } as google.maps.Size,
      anchor: { x: 18, y: 18 } as google.maps.Point,
    };
  }

  center: google.maps.LatLngLiteral = { lat: 51.1657, lng: 10.4515 }; // geografische Mitte Deutschlands
  zoom = 6;
  mapOptions: google.maps.MapOptions = {
    streetViewControl: false,
    fullscreenControl: false,
    mapTypeControl: false,
  };

  selected = signal<StadiumMapEntry | null>(null);

  openInfo(marker: MapMarker, stadium: StadiumMapEntry): void {
    this.selected.set(stadium);
    this.infoWindow.open(marker);
  }

  constructor() {
    this.mapsLoader.load();
  }
}
