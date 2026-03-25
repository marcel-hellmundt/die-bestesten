import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, map, of, startWith } from 'rxjs';
import { toSignal } from '@angular/core/rxjs-interop';
import { ApiService } from '../core/api.service';
import { AuthService } from '../auth/auth.service';

interface ManagerProfile {
  id: string;
  manager_name: string;
  alias: string | null;
  role: string;
  status: string;
}

@Component({
  selector: 'app-settings',
  standalone: false,
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.scss'
})
export class SettingsComponent {
  private api    = inject(ApiService);
  private auth   = inject(AuthService);
  private router = inject(Router);

  // Profile
  private profileState = toSignal(
    this.api.get<ManagerProfile>('manager/me').pipe(
      map(data => ({ data, loading: false, error: null as string | null })),
      startWith({ data: null as ManagerProfile | null, loading: true, error: null as string | null }),
      catchError(() => of({ data: null as ManagerProfile | null, loading: false, error: 'Profil konnte nicht geladen werden' }))
    )
  );

  profile        = computed(() => this.profileState()?.data ?? null);
  profileLoading = computed(() => this.profileState()?.loading ?? true);
  profileError   = computed(() => this.profileState()?.error ?? null);

  managerId    = computed(() => this.auth.getManagerId());
  avatarUrl    = computed(() => {
    const id = this.managerId();
    return id ? `https://img.die-bestesten.de/img/manager/${id}.jpg` : null;
  });
  avatarFailed = signal(false);
  initials     = computed(() => {
    const name = this.auth.getManagerName() ?? '';
    return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() || '?';
  });

  // Password change
  currentPw = signal('');
  newPw     = signal('');
  confirmPw = signal('');
  pwState   = signal<'idle' | 'loading' | 'success' | 'error'>('idle');
  pwError   = signal<string | null>(null);

  changePassword(): void {
    if (this.newPw() !== this.confirmPw()) {
      this.pwError.set('Passwörter stimmen nicht überein');
      return;
    }
    if (this.newPw().length < 6) {
      this.pwError.set('Neues Passwort muss mindestens 6 Zeichen lang sein');
      return;
    }
    this.pwState.set('loading');
    this.pwError.set(null);
    this.api.patch<any>('manager/me', {
      current_password: this.currentPw(),
      new_password:     this.newPw(),
    }).subscribe({
      next: () => {
        this.pwState.set('success');
        this.currentPw.set('');
        this.newPw.set('');
        this.confirmPw.set('');
      },
      error: (err) => {
        this.pwState.set('error');
        this.pwError.set(err.error?.message ?? 'Fehler beim Ändern des Passworts');
      },
    });
  }

  // Delete account
  deleteConfirmVisible = signal(false);
  deletePw    = signal('');
  deleteState = signal<'idle' | 'loading' | 'error'>('idle');
  deleteError = signal<string | null>(null);

  showDeleteConfirm(): void {
    this.deletePw.set('');
    this.deleteError.set(null);
    this.deleteConfirmVisible.set(true);
  }

  cancelDelete(): void {
    this.deleteConfirmVisible.set(false);
  }

  deleteAccount(): void {
    this.deleteState.set('loading');
    this.deleteError.set(null);
    this.api.delete<any>('manager/me', { password: this.deletePw() }).subscribe({
      next: () => {
        this.auth.logout();
        this.router.navigate(['/login']);
      },
      error: (err) => {
        this.deleteState.set('error');
        this.deleteError.set(err.error?.message ?? 'Fehler beim Löschen des Kontos');
      },
    });
  }
}
