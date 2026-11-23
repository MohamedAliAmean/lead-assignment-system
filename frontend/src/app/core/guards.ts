import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthStore } from './auth.store';

// Note: inject() only works synchronously inside the guard's injection context,
// so every dependency is resolved before the first `await`.

/** Only for signed-in users; loads the profile once. */
export const authGuard: CanActivateFn = async () => {
  const auth = inject(AuthStore);
  const router = inject(Router);

  return (await auth.ensureUser()) ? true : router.parseUrl('/login');
};

/** Login page only for guests. */
export const guestGuard: CanActivateFn = async () => {
  const auth = inject(AuthStore);
  const router = inject(Router);

  return (await auth.ensureUser()) ? router.parseUrl('/') : true;
};
