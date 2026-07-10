import { Injectable, signal } from '@angular/core';
import { environment } from '../../environments/environment';

@Injectable({ providedIn: 'root' })
export class GoogleMapsLoaderService {
  readonly ready = signal(false);
  private loadPromise: Promise<void> | null = null;

  load(): Promise<void> {
    if (this.loadPromise) return this.loadPromise;

    this.loadPromise = new Promise<void>((resolve, reject) => {
      if ((window as any).google?.maps) {
        this.ready.set(true);
        resolve();
        return;
      }
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${environment.googleMapsApiKey}&loading=async`;
      script.async = true;
      script.onload = () => { this.ready.set(true); resolve(); };
      script.onerror = () => reject(new Error('Google Maps script failed to load'));
      document.head.appendChild(script);
    });

    return this.loadPromise;
  }
}
