import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { Division } from '../../core/models/division.model';
import { Country } from '../../core/models/country.model';

@Component({
  selector: 'app-data-division',
  standalone: false,
  templateUrl: './division.component.html',
  styleUrl: './division.component.scss'
})
export class DivisionDataComponent {
  private api    = inject(ApiService);
  private router = inject(Router);
  private route  = inject(ActivatedRoute);

  navigate(id: string): void { this.router.navigate([id], { relativeTo: this.route }); }

  private reload$ = new BehaviorSubject<void>(undefined);

  private state = toSignal(
    this.reload$.pipe(
      switchMap(() => this.api.get<Division[]>('division').pipe(
        map(data => ({ data, loading: false, error: null as string | null })),
        startWith({ data: [] as Division[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Division[], loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  items   = computed(() => this.state()?.data    ?? []);
  loading = computed(() => this.state()?.loading ?? true);
  error   = computed(() => this.state()?.error   ?? null);

  searchQuery   = signal('');
  filteredItems = computed(() => {
    const q = this.searchQuery().toLowerCase().trim();
    if (!q) return this.items();
    return this.items().filter(i => i.name.toLowerCase().includes(q));
  });

  countries = toSignal(
    this.api.get<any[]>('country').pipe(
      map(data => data.map(Country.from)),
      catchError(() => of([] as Country[]))
    ),
    { initialValue: [] as Country[] }
  );

  private countryById = computed(() => new Map(this.countries().map(c => [c.id, c])));

  groupedByCountry = computed(() => {
    const byCountry = new Map<string, Division[]>();
    for (const d of this.filteredItems()) {
      if (!byCountry.has(d.country_id)) byCountry.set(d.country_id, []);
      byCountry.get(d.country_id)!.push(d);
    }
    const countries = this.countryById();
    return Array.from(byCountry.entries())
      .map(([countryId, divisions]) => ({
        countryId,
        countryName: countries.get(countryId)?.name ?? countryId,
        rank: countries.get(countryId)?.rank ?? null,
        divisions: [...divisions].sort((a, b) => a.level - b.level),
      }))
      // rank (gepflegt in der DB, nur fürs Sortieren dieser Cards) hat Vorrang; unbepunktete
      // Länder (rank NULL) fallen ans Ende zurück und werden untereinander wie bisher nach
      // Divisionsanzahl absteigend sortiert.
      .sort((a, b) => {
        if (a.rank !== null && b.rank !== null) return a.rank - b.rank;
        if (a.rank !== null) return -1;
        if (b.rank !== null) return 1;
        return b.divisions.length - a.divisions.length;
      });
  });
}
