import { Injectable } from '@angular/core';
import { HttpInterceptor, HttpRequest, HttpHandler, HttpEvent, HttpResponse, HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap, catchError, throwError } from 'rxjs';
import { AuthService } from '../auth/auth.service';

@Injectable()
export class TokenRefreshInterceptor implements HttpInterceptor {
  private readonly TOKEN_KEY = 'auth_token';

  constructor(private authService: AuthService, private router: Router) {}

  intercept(req: HttpRequest<unknown>, next: HttpHandler): Observable<HttpEvent<unknown>> {
    return next.handle(req).pipe(
      tap(event => {
        if (event instanceof HttpResponse) {
          const newToken = event.headers.get('X-New-Token');
          if (newToken) {
            localStorage.setItem(this.TOKEN_KEY, newToken);
          }
        }
      }),
      catchError((error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 401 && this.authService.getToken()) {
          this.authService.logout();
          this.router.navigate(['/login']);
        }
        // Umgebung nur für Admins (API: ADMIN_ONLY im .env, z.B. development-Domain) — abmelden + Hinweis im Login
        if (error instanceof HttpErrorResponse && error.status === 403 && error.error?.admin_only && this.authService.getToken()) {
          this.authService.logout();
          this.router.navigate(['/login'], { queryParams: { admin_only: 1 } });
        }
        return throwError(() => error);
      })
    );
  }
}
