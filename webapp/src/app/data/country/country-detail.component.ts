import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { Country } from '../../core/models/country.model';
import { Club } from '../../core/models/club.model';

@Component({
  selector: 'app-country-detail',
  standalone: false,
  templateUrl: './country-detail.component.html',
  styleUrl: './country-detail.component.scss'
})
export class CountryDetailComponent {
  private api   = inject(ApiService);
  private route = inject(ActivatedRoute);

  private id$ = this.route.paramMap.pipe(map(p => p.get('id')!));

  private countryState = toSignal(
    this.id$.pipe(
      switchMap(id => this.api.get<any>(`country/${id}`).pipe(
        map(data => ({ data: Country.from(data), loading: false, error: null as string | null })),
        startWith({ data: null as Country | null, loading: true, error: null as string | null }),
        catchError(() => of({ data: null as Country | null, loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  private clubsState = toSignal(
    this.id$.pipe(
      switchMap(id => this.api.get<any[]>(`club?country_id=${id}`).pipe(
        map(data => ({
          data: data.map(Club.from).sort((a, b) => a.name.localeCompare(b.name)),
          loading: false,
          error: null as string | null
        })),
        startWith({ data: [] as Club[], loading: true, error: null as string | null }),
        catchError(() => of({ data: [] as Club[], loading: false, error: 'Fehler beim Laden' }))
      ))
    )
  );

  country      = computed(() => this.countryState()?.data ?? null);
  loading      = computed(() => this.countryState()?.loading ?? true);
  error        = computed(() => this.countryState()?.error ?? null);
  clubs        = computed(() => this.clubsState()?.data ?? []);
  clubsLoading = computed(() => this.clubsState()?.loading ?? true);
}
