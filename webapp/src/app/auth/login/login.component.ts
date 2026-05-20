import { Component, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService, League } from '../auth.service';
import { ApiService } from '../../core/api.service';

type Mode = 'login' | 'request' | 'sent' | 'choose-league' | 'join-league';

@Component({
  selector: 'app-login',
  standalone: false,
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss'
})
export class LoginComponent {
  form: FormGroup;
  emailForm: FormGroup;
  loading = false;
  error: string | null = null;
  mode = signal<Mode>('login');
  showPassword = false;
  requestLoading = false;
  requestError: string | null = null;

  myLeagues   = signal<League[]>([]);
  allLeagues  = signal<League[]>([]);
  joinLoading = signal<string | null>(null);

  constructor(
    private fb:     FormBuilder,
    private auth:   AuthService,
    private router: Router,
    private api:    ApiService
  ) {
    this.form = this.fb.group({
      name:     ['', Validators.required],
      password: ['', Validators.required]
    });
    this.emailForm = this.fb.group({
      email: ['', [Validators.required, Validators.email]]
    });
  }

  submit(): void {
    if (this.form.invalid || this.loading) return;
    this.loading = true;
    this.error   = null;

    const { name, password } = this.form.value;
    this.auth.login(name, password).subscribe({
      next: response => {
        this.loading = false;
        if (response.league_id) {
          this.router.navigate(['/']);
          return;
        }
        this.myLeagues.set(response.leagues ?? []);
        if (response.leagues?.length > 1) {
          this.mode.set('choose-league');
        } else {
          this.loadAllLeagues();
          this.mode.set('join-league');
        }
      },
      error: () => {
        this.error   = 'Name oder Passwort inkorrekt';
        this.loading = false;
        this.form.get('password')?.setValue('');
      }
    });
  }

  private loadAllLeagues(): void {
    this.api.get<any[]>('league').subscribe({
      next: data => this.allLeagues.set(data ?? []),
      error: ()  => {},
    });
  }

  selectLeague(leagueId: string): void {
    this.auth.switchLeague(leagueId).subscribe({
      next: () => this.router.navigate(['/']),
      error: () => {},
    });
  }

  joinLeague(league: any): void {
    if (this.joinLoading()) return;
    this.joinLoading.set(league.id);
    this.api.post<any>(`league/${league.id}/join`, {}).subscribe({
      next: () => {
        this.joinLoading.set(null);
        this.selectLeague(league.id);
      },
      error: () => this.joinLoading.set(null),
    });
  }

  submitResetRequest(): void {
    if (this.emailForm.invalid || this.requestLoading) return;
    this.requestLoading = true;
    this.requestError = null;

    this.api.post<any>('auth/password-reset-request', {
      email: this.emailForm.value.email
    }).subscribe({
      next: () => {
        this.mode.set('sent');
        this.requestLoading = false;
      },
      error: () => {
        this.requestError = 'Fehler beim Senden. Bitte versuche es erneut.';
        this.requestLoading = false;
      }
    });
  }

  showRequest(): void {
    this.mode.set('request');
    this.requestError = null;
    this.emailForm.reset();
  }

  backToLogin(): void {
    this.mode.set('login');
    this.error = null;
  }
}
