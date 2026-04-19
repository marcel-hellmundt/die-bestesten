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

  formatMoney(amount: number): string {
    const abs = Math.abs(amount);
    if (abs >= 1_000_000) return `${(amount / 1_000_000).toLocaleString('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} Mio.`;
    return amount.toLocaleString('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' €';
  }

  formatDate(dateStr: string): string {
    const d = new Date(dateStr);
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }
}
