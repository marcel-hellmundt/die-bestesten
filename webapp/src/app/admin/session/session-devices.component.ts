import { Component, computed, inject, input } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DataCacheService } from '../../core/data-cache.service';

type RangeKey = 'today' | 'day' | 'month' | 'year' | 'all';
type ThemePref = 'light' | 'dark' | 'system';

interface SessionDevice {
  device_type: 'mobile' | 'tablet' | 'desktop' | null;
  os: string | null;
  browser: string | null;
  os_version: string | null;
  device_model: string | null;
  screen: string | null;          // "kurz×lang@Pixeldichte"
  theme_pref: ThemePref | null;
  theme: 'light' | 'dark' | null;
  system_theme: 'light' | 'dark' | null;
  standalone: boolean | null;
  sessions: number;
  seconds: number;
  last_seen: string;
}

interface DevicesResponse {
  range: RangeKey;
  managers: { manager_id: string; manager_name: string; devices: SessionDevice[] }[];
}

/**
 * iPhones melden kein Modell — Bildschirm (Punkte, kurz×lang) + Pixeldichte ergeben nur eine Gruppe
 * baugleicher Displays. Näherung, nur für die Anzeige.
 */
const IPHONE_BY_SCREEN: Record<string, string> = {
  '320x568@2': 'iPhone SE (1.) / 5s',
  '375x667@2': 'iPhone SE (2./3.) / 6–8',
  '414x736@3': 'iPhone 6–8 Plus',
  '375x812@3': 'iPhone 12/13 mini, X, XS, 11 Pro',
  '414x896@2': 'iPhone XR / 11',
  '414x896@3': 'iPhone XS Max / 11 Pro Max',
  '390x844@3': 'iPhone 12–14, 16e',
  '428x926@3': 'iPhone 12/13 Pro Max, 14 Plus',
  '393x852@3': 'iPhone 14 Pro, 15, 16',
  '430x932@3': 'iPhone 14/15 Pro Max, 15/16 Plus',
  '420x912@3': 'iPhone Air',
  '402x874@3': 'iPhone 16 Pro, 17, 17 Pro',
  '440x956@3': 'iPhone 16/17 Pro Max',
};

const PREF_LABEL: Record<ThemePref, string> = { light: 'Hell', dark: 'Dunkel', system: 'System' };
const THEME_LABEL = { light: 'Hell', dark: 'Dunkel' } as const;

/** Anteile in der Theme-Wahl-Leiste */
const PREF_ORDER: ThemePref[] = ['light', 'dark', 'system'];

/**
 * "Geräte & Erscheinung" unter der Nutzungs-Heatmap (/verwaltung/nutzung): welche Geräte jeder Manager
 * nutzt und welches Theme darauf läuft — Grundlage: GET /session/devices (Header X-Client-Info,
 * siehe core/client-info.service.ts). Gleicher Zeitraum wie die Heatmap darüber.
 */
@Component({
  selector: 'app-session-devices',
  standalone: false,
  templateUrl: './session-devices.component.html',
  styleUrl: './session-devices.component.scss',
})
export class SessionDevicesComponent {
  private api = inject(ApiService);
  cache = inject(DataCacheService);

  range = input.required<RangeKey>();

  private data = toSignal(toObservable(this.range).pipe(
    switchMap(r => this.api.get<DevicesResponse>(`session/devices?range=${r}`).pipe(
      catchError(() => of({ range: r, managers: [] } as DevicesResponse)),
    )),
  ));

  loading = computed(() => this.data() === undefined);
  managers = computed(() => this.data()?.managers ?? []);

  private allDevices = computed(() => this.managers().flatMap(m => m.devices));
  /** Geräte, die schon Theme-Infos geschickt haben (erst ab diesem Release bzw. nach der Migration) */
  private tracked = computed(() => this.allDevices().filter(d => d.theme_pref !== null));

  hasTracking = computed(() => this.tracked().length > 0);

  /** Theme-Wahl je Gerät als Anteile (Leiste + Legende) */
  prefShares = computed(() => {
    const devices = this.tracked();
    const total = devices.length;
    return PREF_ORDER.map(pref => {
      const count = devices.filter(d => d.theme_pref === pref).length;
      return { pref, label: PREF_LABEL[pref], count, pct: total ? Math.round(count / total * 100) : 0 };
    });
  });

  trackedCount = computed(() => this.tracked().length);

  /** Aktuell dunkel angezeigt (Dunkel oder System→Dunkel) */
  darkShown = computed(() => this.tracked().filter(d => d.theme === 'dark').length);

  /** Potenzial: Gerät steht im System auf Dunkel, die App wird aber hell angezeigt */
  darkSystemLightApp = computed(() => this.tracked().filter(d => d.system_theme === 'dark' && d.theme === 'light').length);

  installed = computed(() => {
    const known = this.allDevices().filter(d => d.standalone !== null);
    return { count: known.filter(d => d.standalone).length, total: known.length };
  });

  /**
   * Card "Geräte-Aufteilung": Computer vs. Mobil (Tablet zählt zu Mobil, unbekannter Typ zu Computer — wie in der
   * Heatmap) als Leiste, je Gruppe darunter Betriebssysteme und Browser. Gezählt werden Geräte, nicht Nutzungszeit.
   */
  deviceSplit = computed(() => {
    const all = this.allDevices();
    const total = all.length;
    const pct = (n: number, of: number) => (of ? Math.round(n / of * 100) : 0);
    const tally = (devices: SessionDevice[], key: (d: SessionDevice) => string | null) => {
      const counts = new Map<string, number>();
      for (const d of devices) {
        const label = key(d) ?? 'Unbekannt';
        counts.set(label, (counts.get(label) ?? 0) + 1);
      }
      return [...counts.entries()]
        .map(([label, count]) => ({ label, count, pct: pct(count, devices.length) }))
        .sort((a, b) => b.count - a.count || a.label.localeCompare(b.label));
    };
    const group = (key: 'desktop' | 'mobile', label: string, icon: string) => {
      const devices = all.filter(d => (d.device_type === 'mobile' || d.device_type === 'tablet') === (key === 'mobile'));
      return {
        key, label, icon, count: devices.length, pct: pct(devices.length, total),
        os: tally(devices, d => d.os),
        browsers: tally(devices, d => d.browser),
      };
    };
    return { total, groups: [group('desktop', 'Computer', 'monitor'), group('mobile', 'Mobil', 'phone')] };
  });

  deviceName(d: SessionDevice): string {
    if (d.device_model) return d.device_model;
    if (d.os === 'iOS' && d.device_type === 'mobile' && d.screen) {
      const group = IPHONE_BY_SCREEN[d.screen];
      if (group) return group;
    }
    if (d.os === 'iOS') return d.device_type === 'tablet' ? 'iPad' : 'iPhone';
    const type = d.device_type === 'mobile' ? 'Handy' : d.device_type === 'tablet' ? 'Tablet' : 'Computer';
    return d.os ? `${type} (${d.os})` : type;
  }

  deviceIcon(d: SessionDevice): string {
    return d.device_type === 'desktop' || d.device_type === null ? 'monitor' : 'phone';
  }

  /** "iOS 17.5 · Safari" */
  deviceMeta(d: SessionDevice): string {
    const os = d.os ? (d.os_version ? `${d.os} ${d.os_version}` : d.os) : null;
    return [os, d.browser].filter(Boolean).join(' · ') || 'Unbekannt';
  }

  themeLabel(d: SessionDevice): string | null {
    if (!d.theme_pref) return null;
    if (d.theme_pref === 'system' && d.theme) return `System · ${THEME_LABEL[d.theme]}`;
    return PREF_LABEL[d.theme_pref];
  }

  /** Potenzial-Hinweis am Gerät */
  isDarkSystemLightApp(d: SessionDevice): boolean {
    return d.system_theme === 'dark' && d.theme === 'light';
  }

  lastSeen(d: SessionDevice): string {
    return new Date(d.last_seen.replace(' ', 'T')).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
  }

  duration(seconds: number): string {
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60), min = minutes % 60;
    return min ? `${h} h ${min} min` : `${h} h`;
  }

  photoFailed = new Set<string>();
}
