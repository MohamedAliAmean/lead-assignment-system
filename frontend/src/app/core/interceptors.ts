import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthStore } from './auth.store';
import { ApiError } from './models';

/** Adds the bearer token and asks for JSON on every API call. */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith('/api/')) return next(request);

  const token = inject(AuthStore).token();
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  return next(request.clone({ setHeaders: headers }));
};

/**
 * Turns every failed API response into a typed ApiError (the server's
 * { message, code, errors, context } envelope) and ends the session on 401.
 */
export const apiErrorInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(AuthStore);
  const router = inject(Router);

  return next(request).pipe(
    catchError((error: unknown) => {
      if (!(error instanceof HttpErrorResponse)) return throwError(() => error);

      const body = (error.error ?? {}) as Partial<{ message: string; code: string; errors: Record<string, string[]>; context: Record<string, unknown> }>;
      const apiError = new ApiError(
        error.status,
        body.code ?? (error.status === 0 ? 'network_error' : 'http_error'),
        body.message ?? (error.status === 0 ? 'Cannot reach the server.' : 'Something went wrong.'),
        body.errors ?? {},
        body.context ?? {},
      );

      const isLogin = request.url.endsWith('/auth/tokens') && request.method === 'POST';
      if (error.status === 401 && !isLogin) {
        auth.clear();
        void router.navigateByUrl('/login');
      }

      return throwError(() => apiError);
    }),
  );
};
