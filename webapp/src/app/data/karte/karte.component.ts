import { Component, ViewChild, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith } from 'rxjs';
import { MapInfoWindow, MapMarker } from '@angular/google-maps';
import { ApiService } from '../../core/api.service';
import { GoogleMapsLoaderService } from '../../core/google-maps-loader.service';
import { StadiumMapEntry } from '../../core/models/stadium.model';

interface MarkerViewModel {
  stadium: StadiumMapEntry;
  position: google.maps.LatLngLiteral;
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

  markers = computed<MarkerViewModel[]>(() =>
    this.stadiums()
      .filter(s => s.lat != null && s.lng != null)
      .map(s => ({ stadium: s, position: { lat: s.lat as number, lng: s.lng as number } }))
  );

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
