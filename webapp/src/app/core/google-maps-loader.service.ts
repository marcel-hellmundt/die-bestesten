import { Injectable, signal } from '@angular/core';
import { environment } from '../../environments/environment';

@Injectable({ providedIn: 'root' })
export class GoogleMapsLoaderService {
  readonly ready = signal(false);
  private installed = false;

  // Installs Google's official "dynamic library import" bootstrap loader. It defines
  // google.maps.importLibrary synchronously; @angular/google-maps calls that function
  // itself for every library it needs (maps, marker, ...), which triggers the actual
  // API script fetch lazily on first use. A plain <script src="…/js?..."> tag does NOT
  // define importLibrary and breaks current @angular/google-maps versions.
  load(): void {
    if (this.installed) return;
    this.installed = true;

    (g => {
      let h: Promise<void> | undefined;
      let a: HTMLScriptElement;
      const p = 'The Google Maps JavaScript API';
      const c = 'google';
      const l = 'importLibrary';
      const q = '__ib__';
      const m = document;
      const b: any = window;
      const bb = (b[c] = b[c] || {});
      const d = (bb.maps = bb.maps || {});
      const r = new Set<string>();
      const e = new URLSearchParams();
      const u = () =>
        h ||
        (h = new Promise<void>((resolve, reject) => {
          a = m.createElement('script');
          e.set('libraries', [...r].join(','));
          for (const k in g) e.set(k.replace(/[A-Z]/g, t => '_' + t[0].toLowerCase()), (g as any)[k]);
          e.set('callback', c + '.maps.' + q);
          a.src = `https://maps.${c}apis.com/maps/api/js?` + e;
          d[q] = resolve;
          a.onerror = () => {
            h = undefined;
            reject(new Error(p + ' could not load.'));
          };
          a.nonce = (m.querySelector('script[nonce]') as HTMLScriptElement | null)?.nonce ?? '';
          m.head.append(a);
        }));
      d[l]
        ? console.warn(p + ' only loads once. Ignoring:', g)
        : (d[l] = (f: string, ...n: any[]) => r.add(f) && u().then(() => d[l](f, ...n)));
    })({
      key: environment.googleMapsApiKey,
      v: 'weekly',
    });

    this.ready.set(true);
  }
}
