import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { AuthService } from '../auth/auth.service';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private base = environment.apiUrl;

  constructor(private http: HttpClient, private auth: AuthService) {}

  get<T>(path: string): Observable<T> {
    const token = this.auth.getToken();
    const headers = token ? new HttpHeaders({ Authorization: `Bearer ${token}` }) : undefined;
    return this.http.get<T>(`${this.base}/${path}`, { headers });
  }

  post<T>(path: string, body: unknown = {}): Observable<T> {
    const token = this.auth.getToken();
    const headers = token ? new HttpHeaders({ Authorization: `Bearer ${token}` }) : undefined;
    return this.http.post<T>(`${this.base}/${path}`, body, { headers });
  }

  patch<T>(path: string, body: unknown = {}): Observable<T> {
    const token = this.auth.getToken();
    const headers = token ? new HttpHeaders({ Authorization: `Bearer ${token}` }) : undefined;
    return this.http.patch<T>(`${this.base}/${path}`, body, { headers });
  }

  delete<T>(path: string, body: unknown = {}): Observable<T> {
    const token = this.auth.getToken();
    const headers = token ? new HttpHeaders({ Authorization: `Bearer ${token}` }) : undefined;
    return this.http.delete<T>(`${this.base}/${path}`, { headers, body });
  }
}
