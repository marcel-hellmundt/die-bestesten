import { Component, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../auth.service';

@Component({
  selector: 'app-accept-invite',
  standalone: false,
  templateUrl: './accept-invite.component.html',
  styleUrl: './accept-invite.component.scss'
})
export class AcceptInviteComponent implements OnInit {
  form: FormGroup;
  token: string | null = null;
  loading = false;
  error: string | null = null;

  constructor(
    private fb: FormBuilder,
    private route: ActivatedRoute,
    private router: Router,
    private api: ApiService,
    private auth: AuthService
  ) {
    this.form = this.fb.group({
      new_password: ['', [Validators.required, Validators.minLength(8)]]
    });
  }

  ngOnInit(): void {
    this.token = this.route.snapshot.queryParamMap.get('token');
  }

  submit(): void {
    if (this.form.invalid || this.loading || !this.token) return;
    this.loading = true;
    this.error = null;

    this.api.post<any>('auth/password-reset', {
      token: this.token,
      new_password: this.form.value.new_password
    }).subscribe({
      next: (res) => {
        this.auth.setSession(res.token, res.league_id ?? null);
        this.router.navigate(['/']);
      },
      error: (err: any) => {
        this.error = err.error?.message ?? 'Fehler beim Festlegen des Passworts';
        this.loading = false;
      }
    });
  }

  goToLogin(): void {
    this.router.navigate(['/login']);
  }
}
