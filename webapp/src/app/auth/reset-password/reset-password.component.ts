import { Component, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/api.service';

@Component({
  selector: 'app-reset-password',
  standalone: false,
  templateUrl: './reset-password.component.html',
  styleUrl: './reset-password.component.scss'
})
export class ResetPasswordComponent implements OnInit {
  form: FormGroup;
  token: string | null = null;
  loading = false;
  error: string | null = null;
  success = false;

  constructor(
    private fb: FormBuilder,
    private route: ActivatedRoute,
    private router: Router,
    private api: ApiService
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
      next: () => {
        this.success = true;
        this.loading = false;
      },
      error: (err: any) => {
        this.error = err.error?.message ?? 'Fehler beim Zurücksetzen';
        this.loading = false;
      }
    });
  }

  goToLogin(): void {
    this.router.navigate(['/login']);
  }
}
