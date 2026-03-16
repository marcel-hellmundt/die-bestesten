import { Component } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../auth.service';

@Component({
  selector: 'app-login',
  standalone: false,
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss'
})
export class LoginComponent {
  form: FormGroup;
  loading = false;
  error: string | null = null;

  constructor(
    private fb: FormBuilder,
    private auth: AuthService,
    private router: Router
  ) {
    this.form = this.fb.group({
      name:     ['', Validators.required],
      password: ['', Validators.required]
    });
  }

  submit(): void {
    if (this.form.invalid || this.loading) return;
    this.loading = true;
    this.error   = null;

    const { name, password } = this.form.value;
    this.auth.login(name, password).subscribe({
      next: () => this.router.navigate(['/app']),
      error: () => {
        this.error   = 'Name oder Passwort inkorrekt';
        this.loading = false;
        this.form.get('password')?.setValue('');
      }
    });
  }
}
