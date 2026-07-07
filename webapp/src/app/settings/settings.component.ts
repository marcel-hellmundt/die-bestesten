import { Component, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, map, of, startWith } from 'rxjs';
import { toSignal } from '@angular/core/rxjs-interop';
import { ApiService } from '../core/api.service';
import { AuthService } from '../auth/auth.service';
import { DataCacheService } from '../core/data-cache.service';
import { NotificationService } from '../core/notification.service';
import { environment } from '../../environments/environment';

interface ManagerProfile {
  id: string;
  manager_name: string;
  alias: string | null;
  roles: string[];
  status: string;
}

@Component({
  selector: 'app-settings',
  standalone: false,
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.scss'
})
export class SettingsComponent {
  private api       = inject(ApiService);
  private auth      = inject(AuthService);
  private router    = inject(Router);
  private cache     = inject(DataCacheService);
  private notifSvc  = inject(NotificationService);

  @ViewChild('photoInput') photoInput!: ElementRef<HTMLInputElement>;

  preferences    = this.notifSvc.preferences;

  constructor() {
    this.notifSvc.loadPreferences();
  }

  pref(key: string): boolean {
    const val = this.preferences()[key];
    return val === undefined ? true : val;
  }

  setPreference(eventType: string, enabled: boolean): void {
    this.notifSvc.setPreference(eventType, enabled);
  }

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
    return id ? `${environment.imageApiUrl}/img/manager/${id}.jpg?v=${this.avatarBust()}` : null;
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
