import { Component, computed, inject } from '@angular/core';
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

    // Accumulate daily end-of-day balance
    const dayMap = new Map<string, number>();
    let running = 0;
    for (const tx of txs) {
      const day = tx.created_at.slice(0, 10);
      running += tx.amount;
      dayMap.set(day, running);
    }

    const points = Array.from(dayMap.entries()).map(([day, balance]) => ({ day, balance }));
    if (points.length < 2) return null;

    const plotW = this.cW - this.padL - this.padR;
    const plotH = this.cH - this.padT - this.padB;
    const balances = points.map(p => p.balance);
    const minB = Math.min(...balances);
    const maxB = Math.max(...balances);
    const range = maxB - minB || 1;

    const xPos = (i: number) => this.padL + (i / (points.length - 1)) * plotW;
    const yPos = (b: number) => this.padT + plotH - ((b - minB) / range) * plotH;

    const dots = points.map((p, i) => ({ x: xPos(i), y: yPos(p.balance), balance: p.balance, day: p.day }));
    const line = dots.map((d, i) => `${i === 0 ? 'M' : 'L'}${d.x.toFixed(1)},${d.y.toFixed(1)}`).join(' ');

    const yTicks = [
      { y: this.padT,          label: this.formatMoney(maxB) },
      { y: this.padT + plotH,  label: this.formatMoney(minB) },
    ];

    const xTicks = [
      { x: xPos(0),               label: this.formatDateShort(points[0].day) },
      { x: xPos(points.length - 1), label: this.formatDateShort(points[points.length - 1].day) },
    ];

    return { dots, line, yTicks, xTicks };
  });

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
