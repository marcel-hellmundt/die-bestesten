import { Component, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, map, of, startWith } from 'rxjs';
import { toSignal } from '@angular/core/rxjs-interop';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../auth/auth.service';
import { environment } from '../../../environments/environment';

interface ManagerProfile {
  id: string;
  manager_name: string;
  alias: string | null;
  email: string | null;
  roles: string[];
  status: string;
}

/** /einstellungen/allgemein — Mein Konto, E-Mail ändern, Passwort ändern, Konto löschen. */
@Component({
  selector: 'app-settings-general',
  standalone: false,
  templateUrl: './settings-general.component.html',
  styleUrl: './settings-general.component.scss',
})
export class SettingsGeneralComponent {
  private api    = inject(ApiService);
  private auth   = inject(AuthService);
  private router = inject(Router);

  @ViewChild('photoInput') photoInput!: ElementRef<HTMLInputElement>;

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

  managerId      = computed(() => this.auth.getManagerId());
  avatarFailed   = signal(false);
  private avatarBust = signal(Date.now());
  avatarUrl      = computed(() => {
    const id = this.managerId();
    return id ? `${environment.imageApiUrl}/manager/${id}.jpg?v=${this.avatarBust()}` : null;
  });
  photoState     = signal<'idle' | 'loading' | 'error'>('idle');
  initials     = computed(() => {
    const name = this.auth.getManagerName() ?? '';
    return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() || '?';
  });

  primaryRole(p: ManagerProfile): string {
    if (p.roles.includes('admin'))      return 'admin';
    if (p.roles.includes('maintainer')) return 'maintainer';
    return 'manager';
  }

  // E-Mail ändern (aktuelles Passwort als Bestätigung)
  private emailOverride = signal<string | null>(null);
  currentEmail = computed(() => this.emailOverride() ?? this.profile()?.email ?? null);
  newEmail     = signal('');
  emailPw      = signal('');
  emailState   = signal<'idle' | 'loading' | 'success' | 'error'>('idle');
  emailError   = signal<string | null>(null);

  changeEmail(): void {
    const email = this.newEmail().trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      this.emailError.set('Bitte gib eine gültige E-Mail-Adresse ein');
      return;
    }
    this.emailState.set('loading');
    this.emailError.set(null);
    this.api.patch<any>('manager/me', { current_password: this.emailPw(), email }).subscribe({
      next: () => {
        this.emailState.set('success');
        this.emailOverride.set(email);
        this.newEmail.set('');
        this.emailPw.set('');
      },
      error: (err) => {
        this.emailState.set('error');
        this.emailError.set(err.error?.message ?? 'Fehler beim Ändern der E-Mail-Adresse');
      },
    });
  }

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

  // Photo upload
  triggerPhotoUpload(): void {
    this.photoInput.nativeElement.click();
  }

  onPhotoSelected(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    (e.target as HTMLInputElement).value = '';
    const id = this.managerId();
    if (!id) return;

    this.photoState.set('loading');
    this.api.uploadManagerPhoto(file).subscribe({
      next: () => {
        this.avatarFailed.set(false);
        this.avatarBust.set(Date.now());
        this.photoState.set('idle');
      },
      error: () => this.photoState.set('error'),
    });
  }
}
