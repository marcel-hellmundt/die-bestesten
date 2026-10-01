import { Injectable, inject, signal } from '@angular/core';
import { ThemeService } from './theme.service';

/** Chromium-only (Chrome/Edge/Samsung/Opera) — in Safari/Firefox nicht vorhanden */
interface UserAgentData {
  platform: string;
  getHighEntropyValues(hints: string[]): Promise<{ platformVersion?: string; model?: string }>;
}

/**
 * Geräte-/Theme-Infos für die Admin-Auswertung "Geräte & Erscheinung" (/verwaltung/nutzung) — ApiService hängt
 * header() als X-Client-Info an jeden authentifizierten Request, das Backend speichert es an der
 * manager_session (SessionTrait::storeSessionClientInfo). Bewusst grob: kein Fingerabdruck, kein
 * eindeutiges Gerät. Werte:
 *  theme/shown/scheme — Theme-Wahl, angezeigtes Theme, Systemeinstellung des Geräts
 *  pwa    — 1 = als App vom Home-Bildschirm gestartet
 *  osv    — OS-Version: Client Hints (Chromium; einzige Quelle für Windows 10 vs. 11 und echte
 *           Android-Version), sonst aus dem User-Agent (iOS, Android)
 *  model  — Gerätemodell, nur Android per Client Hints (iPhones melden kein Modell)
 *  screen — kurze×lange Bildschirmseite@Pixeldichte (iPhone-Größenklasse, im Admin-Frontend gedeutet)
 */
@Injectable({ providedIn: 'root' })
export class ClientInfoService {
  private theme = inject(ThemeService);
  private hints = signal<{ osv?: string; model?: string }>({});
  private readonly base = collectBase();

  constructor() {
    const uad = (navigator as Navigator & { userAgentData?: UserAgentData }).userAgentData;
    uad?.getHighEntropyValues(['platformVersion', 'model'])
      .then(v => this.hints.set({ osv: osFromHints(uad.platform, v.platformVersion), model: cleanModel(v.model) }))
      .catch(() => { /* nicht verfügbar — dann nur User-Agent-Werte */ });
  }

  header(): string {
    const hints = this.hints();
    const params = new URLSearchParams({
      theme:  this.theme.preference(),
      shown:  this.theme.theme(),
      scheme: this.theme.systemTheme(),
      pwa:    this.base.pwa,
      screen: this.base.screen,
    });
    const osv = hints.osv ?? this.base.osv;
    if (osv) params.set('osv', osv);
    if (hints.model) params.set('model', hints.model);
    return params.toString();
  }
}

function collectBase(): { pwa: string; screen: string; osv: string | null } {
  const standalone = matchMedia('(display-mode: standalone)').matches
    || (navigator as Navigator & { standalone?: boolean }).standalone === true;
  const w = screen.width, h = screen.height;
  const dpr = Math.round((window.devicePixelRatio || 1) * 100) / 100;
  return {
    pwa:    standalone ? '1' : '0',
    screen: `${Math.min(w, h)}x${Math.max(w, h)}@${dpr}`,
    osv:    osFromUserAgent(navigator.userAgent),
  };
}

/** iOS: "CPU iPhone OS 17_5 like Mac OS X" → 17.5; Android: "Android 14" (in Chrome eingefroren auf 10) */
function osFromUserAgent(ua: string): string | null {
  const ios = ua.match(/OS (\d+)[_.](\d+)(?:[_.]\d+)? like Mac OS X/);
  if (ios) return `${ios[1]}.${ios[2]}`;
  const android = ua.match(/Android (\d+(?:\.\d+)?)/);
  return android ? android[1] : null;
}

/** Windows: platformVersion ≥ 13 = Windows 11, darunter 10; sonst Version ohne angehängte ".0" */
function osFromHints(platform: string, version?: string): string | undefined {
  if (!version) return undefined;
  if (platform === 'Windows') {
    const major = parseInt(version, 10);
    return major >= 13 ? '11' : major > 0 ? '10' : undefined;
  }
  return version.replace(/(\.0)+$/, '') || undefined;
}

function cleanModel(model?: string): string | undefined {
  const cleaned = (model ?? '').replace(/[^\w .()+\-]/g, '').trim().slice(0, 40);
  return cleaned || undefined;
}
