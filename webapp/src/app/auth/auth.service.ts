import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private readonly TOKEN_KEY = 'auth_token';

  constructor(private http: HttpClient) {}

  login(name: string, password: string): Observable<{ token: string }> {
    return this.http.post<{ token: string }>(`${environment.apiUrl}/auth`, { name, password }).pipe(
      tap(response => {
        localStorage.setItem(this.TOKEN_KEY, response.token);
      })
    );
  }

  logout(): void {
    localStorage.removeItem(this.TOKEN_KEY);
  }

  getToken(): string | null {
    return localStorage.getItem(this.TOKEN_KEY);
  }

  isLoggedIn(): boolean {
    const token = this.getToken();
    if (!token) return false;
    try {
      const payload = this.getPayload();
      if (!payload) return false;
      const exp = payload['exp'] as number | undefined;
      if (exp && Date.now() / 1000 > exp) {
        this.logout();
        return false;
      }
      return true;
    } catch {
      return false;
    }
  }

  getManagerId(): string | null {
    return (this.getPayload()?.['sub'] as string) ?? null;
  }

  getManagerName(): string | null {
    return (this.getPayload()?.['manager_name'] as string) ?? null;
  }

  getRole(): string | null {
    return (this.getPayload()?.['role'] as string) ?? null;
  }

  isAdmin(): boolean {
    return this.getRole() === 'admin';
  }

  isMaintainer(): boolean {
    const role = this.getRole();
    return role === 'maintainer' || role === 'admin';
  }

  getPayload(): Record<string, unknown> | null {
    const token = this.getToken();
    if (!token) return null;
    try {
      const parts = token.split('.');
      if (parts.length !== 3) return null;
      const payload = parts[1];
      const decoded = atob(payload.replace(/-/g, '+').replace(/_/g, '/'));
      return JSON.parse(decoded);
    } catch {
      return null;
    }
  }
}
