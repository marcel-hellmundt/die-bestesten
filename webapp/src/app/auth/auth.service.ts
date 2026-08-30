import { Injectable, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { environment } from '../../environments/environment';
import { ROLE_RANK } from '../core/constants';

export interface League {
  id:     string;
  name:   string;
  slug:   string;
  status?: 'active' | 'invited' | 'requested' | 'denied';
}

export interface LoginResponse {
  token:     string;
  leagues:   League[];
  league_id: string | null;
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private readonly TOKEN_KEY = 'auth_token';

  private readonly _leagueId = signal<string | null>(
    (this.getPayload()?.['league_id'] as string) ?? null
  );

  constructor(private http: HttpClient) {}

  login(name: string, password: string): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${environment.apiUrl}/auth`, { name, password }).pipe(
      tap(response => this.setSession(response.token, response.league_id ?? null))
    );
  }

  switchLeague(leagueId: string): Observable<{ token: string; league_id: string }> {
    return this.http.post<{ token: string; league_id: string }>(
      `${environment.apiUrl}/auth/switch-league`,
      { league_id: leagueId },
      { headers: { Authorization: `Bearer ${this.getToken()}` } }
    ).pipe(
      tap(response => this.setSession(response.token, response.league_id ?? null))
    );
  }

  setSession(token: string, leagueId: string | null): void {
    localStorage.setItem(this.TOKEN_KEY, token);
    this._leagueId.set(leagueId);
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

  getRoles(): string[] {
    return (this.getPayload()?.['roles'] as string[]) ?? [];
  }

  hasRole(role: string): boolean {
    return this.getRoles().includes(role);
  }

  // A manager holds at most one explicit role; its rank also satisfies every requirement
  // ranked at or below it (admin >= maintainer >= contributor >= manager).
  private myRoleRank(): number {
    const role = this.getRoles()[0] ?? 'manager';
    return ROLE_RANK[role] ?? 0;
  }

  isAdmin(): boolean {
    return this.myRoleRank() >= ROLE_RANK['admin'];
  }

  isMaintainer(): boolean {
    return this.myRoleRank() >= ROLE_RANK['maintainer'];
  }

  isContributor(): boolean {
    return this.myRoleRank() >= ROLE_RANK['contributor'];
  }

  getLeagueId(): string | null {
    return this._leagueId();
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
