import { Component, DestroyRef, HostListener, computed, effect, inject, signal, untracked } from '@angular/core';
import { Router } from '@angular/router';
import { environment } from '../../../environments/environment';
import { LukatenService } from '../../core/lukaten.service';
import {
  MatchdaySummary, MatchdaySummaryService, SummaryAchievement, SummaryExtras, SummaryH2H, SummaryHighlight,
  SummaryPack, SummaryResult, SummaryTeam,
} from '../../core/matchday-summary.service';

/**
 * Spieltags-Zusammenfassung als große Einblendung (wie neue Packs oder Achievements): oben das Spiel — Punkte, Platz
 * am Spieltag, Tabellenplatz mit Veränderung, Torschützen, Einnahmen, H2H, Strafe —, darunter was sonst dazukam
 * (Lukaten für Einträge, Tipps, Packs, Achievements). Zeigt, was der MatchdaySummaryService gerade vorgibt; mehrere
 * (mehrere Ligen oder Spieltage) nacheinander mit "Weiter".
 */
@Component({
  selector: 'app-matchday-summary',
  standalone: false,
  templateUrl: './matchday-summary.component.html',
  styleUrl: './matchday-summary.component.scss',
})
export class MatchdaySummaryComponent {
  svc = inject(MatchdaySummaryService);
  private router = inject(Router);

  private index = signal(0);
  count = computed(() => this.svc.shown().length);
  position = computed(() => Math.min(this.index(), Math.max(0, this.count() - 1)));
  current = computed<MatchdaySummary | null>(() => this.svc.shown()[this.position()] ?? null);
  isLast = computed(() => this.position() >= this.count() - 1);

  constructor() {
    // neue Einblendung → wieder bei der ersten anfangen
    effect(() => {
      this.svc.shown();
      untracked(() => this.index.set(0));
    });
    // Shell weg (Abmelden): nichts für den nächsten Manager stehen lassen
    inject(DestroyRef).onDestroy(() => this.svc.close());
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.current()) this.close();
  }

  next(): void {
    if (this.isLast()) this.close();
    else this.index.update(i => i + 1);
  }

  close(): void {
    this.svc.close();
  }

  /** Zum Lukaten-Konto — dort steht die Buchung im Kontoauszug */
  openAccount(): void {
    this.close();
    this.router.navigate(['/lukaten']);
  }

  teamLogoUrl(t: SummaryTeam | SummaryH2H['opponent']): string {
    const id = 'id' in t ? t.id : t.team_id;
    return `${environment.imageApiUrl}/team/${t.season_id}/${id}.png`;
  }

  playerPhotoUrl(p: SummaryHighlight): string {
    return `${environment.imageApiUrl}/player/${p.photo_season_id}/${p.player_id}.png`;
  }

  hide(event: Event): void {
    (event.target as HTMLElement).style.visibility = 'hidden';
  }

  /** Veränderung in der Tabelle gegenüber dem vorherigen Spieltag (by > 0 = geklettert) */
  move(r: SummaryResult): { dir: 'up' | 'down' | 'same'; by: number } | null {
    if (r.table_rank_before === null) return null;
    const by = r.table_rank_before - r.table_rank;
    return { dir: by > 0 ? 'up' : by < 0 ? 'down' : 'same', by: Math.abs(by) };
  }

  /** so oft ein Symbol zeigen (Tore, Vorlagen) — gedeckelt, damit die Zeile nicht umbricht */
  times(n: number): number[] {
    return Array.from({ length: Math.min(Math.max(n, 0), 5) }, (_, i) => i);
  }

  money(v: number): string {
    if (Math.abs(v) >= 1_000_000) {
      return (v / 1_000_000).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Mio. €';
    }
    return v.toLocaleString('de-DE', { maximumFractionDigits: 0 }) + ' €';
  }

  euro(v: number): string {
    return v.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
  }

  lukaten(v: number): string {
    return LukatenService.format(v);
  }

  signedLukaten(v: number): string {
    return (v > 0 ? '+' : v < 0 ? '−' : '±') + LukatenService.format(Math.abs(v));
  }

  outcomeLabel(m: SummaryH2H): string {
    return m.outcome === 'win' ? 'Sieg' : m.outcome === 'loss' ? 'Niederlage' : 'Unentschieden';
  }

  cardsText(r: SummaryResult): string {
    const parts: string[] = [];
    if (r.stats.red_cards > 0) parts.push(`${r.stats.red_cards}× Rot`);
    if (r.stats.yellow_red_cards > 0) parts.push(`${r.stats.yellow_red_cards}× Gelb-Rot`);
    return parts.join(', ');
  }

  hasFacts(r: SummaryResult): boolean {
    return r.income > 0 || r.h2h !== null || r.fine > 0 || this.cardsText(r) !== '';
  }

  hasExtras(e: SummaryExtras): boolean {
    return !!e.lukaten_entries || !!e.bets || e.packs.length > 0 || e.achievements.length > 0;
  }

  /** z.B. "für 12 Einträge: 6 Einsätze, 4 Noten, 2× Statistik" */
  entriesText(e: NonNullable<SummaryExtras['lukaten_entries']>): string {
    const t = e.by_type;
    const count = t.participation + t.note + t.stats;
    if (count === 0) return 'für deine Einträge bei Noten, Einsätzen und Statistik';
    const parts: string[] = [];
    if (t.participation) parts.push(`${t.participation} ${t.participation === 1 ? 'Einsatz' : 'Einsätze'}`);
    if (t.note) parts.push(`${t.note} ${t.note === 1 ? 'Note' : 'Noten'}`);
    if (t.stats) parts.push(`${t.stats}× Statistik`);
    return `für ${count} ${count === 1 ? 'Eintrag' : 'Einträge'}: ${parts.join(', ')}`;
  }

  betsNet(b: NonNullable<SummaryExtras['bets']>): number {
    return b.payouts - b.stakes;
  }

  betsText(b: NonNullable<SummaryExtras['bets']>): string {
    const tips = `${b.correct} von ${b.tips} ${b.tips === 1 ? 'Tipp' : 'Tipps'} richtig`;
    if (b.stakes <= 0) return tips;
    return `${tips} · ${this.lukaten(b.stakes)} gesetzt, ${this.lukaten(b.payouts)} zurück`;
  }

  packTitle(p: SummaryPack): string {
    return p.source === 'matchday_best' ? 'Spieltagssieger-Pack' : 'Meilenstein-Pack';
  }

  packText(p: SummaryPack): string {
    const why = p.source === 'matchday_best' ? 'bestes Team des Spieltags' : `${p.milestone_points} Saisonpunkte erreicht`;
    return `${why} · ${p.size} Sticker`;
  }

  achievementTitle(a: SummaryAchievement): string {
    const level = a.level === 'bronze' ? ' (Bronze)' : a.level === 'silver' ? ' (Silber)' : '';
    return a.name + level;
  }
}
