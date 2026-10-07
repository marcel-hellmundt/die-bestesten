import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { AuthService } from '../auth/auth.service';
import { ClientInfoService } from './client-info.service';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private base = environment.apiUrl;

  constructor(private http: HttpClient, private auth: AuthService, private clientInfo: ClientInfoService) {}

  /** Nur eingeloggt: Token + X-Client-Info (Geräte-/Theme-Infos für GET /session/devices, siehe ClientInfoService) */
  private headers(): HttpHeaders | undefined {
    const token = this.auth.getToken();
    return token
      ? new HttpHeaders({ Authorization: `Bearer ${token}`, 'X-Client-Info': this.clientInfo.header() })
      : undefined;
  }

  get<T>(path: string): Observable<T> {
    return this.http.get<T>(`${this.base}/${path}`, { headers: this.headers() });
  }

  post<T>(path: string, body: unknown = {}): Observable<T> {
    return this.http.post<T>(`${this.base}/${path}`, body, { headers: this.headers() });
  }

  patch<T>(path: string, body: unknown = {}): Observable<T> {
    return this.http.patch<T>(`${this.base}/${path}`, body, { headers: this.headers() });
  }

  postForm<T>(path: string, formData: FormData): Observable<T> {
    return this.http.post<T>(`${this.base}/${path}`, formData, { headers: this.headers() });
  }

  uploadClubLogo(clubId: string, photo: File): Observable<any> {
    const formData = new FormData();
    formData.append('image', photo);
    return this.postForm(`club/${clubId}/logo`, formData);
  }

  uploadPlayerPhoto(playerId: string, seasonId: string, photo: File): Observable<any> {
    const formData = new FormData();
    formData.append('season_id', seasonId);
    formData.append('image', photo);
    return this.postForm(`player/${playerId}/photo`, formData);
  }

  uploadTeamLogo(teamId: string, photo: File): Observable<any> {
    const formData = new FormData();
    formData.append('image', photo);
    return this.postForm(`team/${teamId}/logo`, formData);
  }

  uploadManagerPhoto(photo: File): Observable<any> {
    const formData = new FormData();
    formData.append('image', photo);
    return this.postForm(`manager/me/photo`, formData);
  }

  takeoverTeamLogo(teamId: string): Observable<any> {
    return this.post(`team/${teamId}/logo/takeover`);
  }

  previewPlayerSeasonImport(csv: File, divisionId: string | null): Observable<any> {
    const formData = new FormData();
    formData.append('csv', csv);
    if (divisionId) formData.append('division_id', divisionId);
    return this.postForm('player_in_season/preview_csv', formData);
  }

  importPlayerSeasonRows(rows: { player_id: string; position: string; price: number }[], divisionId: string): Observable<any> {
    return this.post('player_in_season/import_csv', { rows, division_id: divisionId });
  }

  delete<T>(path: string, body: unknown = {}): Observable<T> {
    return this.http.delete<T>(`${this.base}/${path}`, { headers: this.headers(), body });
  }
}
