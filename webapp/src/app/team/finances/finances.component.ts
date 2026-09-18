import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';

interface Transaction {
  id: string;
  amount: number;
  reason: string;
  matchday_id: string | null;
  matchday_number: number | null;
  created_at: string;
}

interface TransactionResponse {
  budget: number;
  transactions: Transaction[];
}

@Component({
  selector: 'app-finances',
  standalone: false,
  templateUrl: './finances.component.html',
  styleUrl: './finances.component.scss',
})
export class FinancesComponent {
  readonly Math = Math;

  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);

  private id$ = this.route.parent!.paramMap.pipe(map(p => p.get('id')!));

  private state = toSignal(
    this.id$.pipe(
      switchMap(id =>
        this.api.get<TransactionResponse>(`transaction?team_id=${id}`).pipe(
          map(data => ({ data, loading: false, error: null as string | null })),
          startWith({ data: null as TransactionResponse | null, loading: true, error: null as string | null }),
          catchError(() => of({ data: null as TransactionResponse | null, loading: false, error: 'Fehler beim Laden' }))
        )
      )
    ),
    { initialValue: { data: null as TransactionResponse | null, loading: true, error: null as string | null } }
  );

  loading      = computed(() => this.state().loading);
  error        = computed(() => this.state().error);
  budget       = computed(() => this.state().data?.budget ?? 0);
  transactions = computed(() => this.state().data?.transactions ?? []);

  readonly cW   = 700;
  readonly cH   = Math.round(700 / 3);
  readonly padL = 56;
  readonly padR = 8;
  readonly padT = 8;
  readonly padB = 20;

  balanceChart = computed(() => {
    const txs = [...this.transactions()].reverse(); // oldest first
    if (txs.length === 0) return null;

    // Accumulate daily end-of-day balance; matchday_number der letzten Transaktion des Tages
    // bestimmt, welchem Spieltag der Tag fürs Banding zugeordnet wird.
    const dayMap = new Map<string, number>();
    const dayMatchday = new Map<string, number | null>();
    let running = 0;
    for (const tx of txs) {
      const day = tx.created_at.slice(0, 10);
      running += tx.amount;
      dayMap.set(day, running);
      dayMatchday.set(day, tx.matchday_number);
    }

    const txDays = Array.from(dayMap.keys()).sort();
    if (txDays.length < 2) return null;

    // Für jeden Kalendertag zwischen erstem und letztem Transaktionstag einen Punkt erzeugen
    // (nicht nur an Tagen mit Transaktion) — Guthaben/Spieltag werden dabei vom letzten bekannten
    // Tag übernommen (Guthaben ändert sich zwischen Transaktionen ja nicht).
    const dayMs = 24 * 60 * 60 * 1000;
    const firstT = new Date(txDays[0]).getTime();
    const lastT  = new Date(txDays[txDays.length - 1]).getTime();
    const points: { day: string; balance: number; matchday: number | null }[] = [];
    let lastBalance = 0;
    let lastMatchday: number | null = null;
    for (let t = firstT; t <= lastT; t += dayMs) {
      const day = new Date(t).toISOString().slice(0, 10);
      if (dayMap.has(day)) {
        lastBalance  = dayMap.get(day)!;
        lastMatchday = dayMatchday.get(day) ?? null;
      }
      points.push({ day, balance: lastBalance, matchday: lastMatchday });
    }

    const plotW = this.cW - this.padL - this.padR;
    const plotH = this.cH - this.padT - this.padB;
    const balances = points.map(p => p.balance);
    const minB = 0;
    const maxB = Math.max(...balances);
    const range = maxB - minB || 1;

    // Echte Datumsskala statt gleichmäßig verteilter Indizes — der zeitliche Abstand zwischen
    // zwei Tagen mit Transaktion ist sonst nicht am Abstand der Punkte ablesbar (mal 1, mal 2,
    // mal 3 Tage, aber optisch immer gleich weit auseinander).
    const times = points.map(p => new Date(p.day).getTime());
    const minT = times[0];
    const maxT = times[times.length - 1];
    const timeRange = maxT - minT || 1;

    const xPos = (t: number) => this.padL + ((t - minT) / timeRange) * plotW;
    const yPos = (b: number) => this.padT + plotH - ((b - minB) / range) * plotH;

    const dots = points.map((p, i) => ({ x: xPos(times[i]), y: yPos(p.balance), balance: p.balance, day: p.day, matchday: p.matchday }));
    const line = dots.map((d, i) => `${i === 0 ? 'M' : 'L'}${d.x.toFixed(1)},${d.y.toFixed(1)}`).join(' ');

    // Volle-Höhe Hover-Spalten je Tag (Segmentgrenze = Mittelpunkt zum jeweiligen Nachbartag) —
    // der Tooltip soll unabhängig von der Y-Position des Cursors erscheinen, solange man
    // irgendwo im Zeitsegment des Tages ist, nicht nur exakt auf der Linie.
    const hoverSegments = dots.map((d, i) => ({
      x1: i === 0 ? this.padL : (dots[i - 1].x + d.x) / 2,
      x2: i === dots.length - 1 ? this.cW - this.padR : (d.x + dots[i + 1].x) / 2,
    }));

    // Abwechselnd eingefärbte Spieltags-Bänder (weiß/hellgrau) im Hintergrund — gruppiert
    // aufeinanderfolgende Punkte mit derselben matchday_number, Bandgrenzen liegen jeweils auf
    // dem Mittelpunkt zwischen dem letzten Punkt einer Gruppe und dem ersten der nächsten.
    const groups: { matchday: number | null; startIdx: number; endIdx: number }[] = [];
    let curMd = points[0].matchday;
    let groupStart = 0;
    for (let i = 1; i < points.length; i++) {
      if (points[i].matchday !== curMd) {
        groups.push({ matchday: curMd, startIdx: groupStart, endIdx: i - 1 });
        curMd = points[i].matchday;
        groupStart = i;
      }
    }
    groups.push({ matchday: curMd, startIdx: groupStart, endIdx: points.length - 1 });

    const bands = groups.map((g, gi) => ({
      x1: gi === 0
        ? this.padL
        : (xPos(times[groups[gi - 1].endIdx]) + xPos(times[g.startIdx])) / 2,
      x2: gi === groups.length - 1
        ? this.cW - this.padR
        : (xPos(times[g.endIdx]) + xPos(times[groups[gi + 1].startIdx])) / 2,
      odd: gi % 2 === 1,
    }));

    const yTicks = [
      { y: this.padT,          label: this.formatMoney(maxB) },
      { y: this.padT + plotH,  label: this.formatMoney(minB) },
    ];

    const xTicks = [
      { x: xPos(times[0]),               label: this.formatDateShort(points[0].day) },
      { x: xPos(times[times.length - 1]), label: this.formatDateShort(points[points.length - 1].day) },
    ];

    return { dots, line, bands, hoverSegments, yTicks, xTicks };
  });

  hoveredDotIndex = signal<number | null>(null);

  formatMoney(amount: number): string {
    const abs = Math.abs(amount);
    if (abs >= 1_000_000) return `${(amount / 1_000_000).toLocaleString('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} Mio.`;
    return amount.toLocaleString('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' €';
  }

  formatDate(dateStr: string): string {
    const d = new Date(dateStr);
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }

  formatDateShort(dateStr: string): string {
    const d = new Date(dateStr);
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
  }
}
