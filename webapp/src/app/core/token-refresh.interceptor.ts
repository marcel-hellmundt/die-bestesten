import { Injectable } from '@angular/core';
import { HttpInterceptor, HttpRequest, HttpHandler, HttpEvent, HttpResponse } from '@angular/common/http';
import { Observable, tap } from 'rxjs';

@Injectable()
export class TokenRefreshInterceptor implements HttpInterceptor {
  private readonly TOKEN_KEY = 'auth_token';

  intercept(req: HttpRequest<unknown>, next: HttpHandler): Observable<HttpEvent<unknown>> {
    return next.handle(req).pipe(
      tap(event => {
        if (event instanceof HttpResponse) {
          const newToken = event.headers.get('X-New-Token');
          if (newToken) {
            localStorage.setItem(this.TOKEN_KEY, newToken);
          }
        }
      })
    );
  }
}
