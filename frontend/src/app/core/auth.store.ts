import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { ApiService } from './api.service';
import { CurrentUser } from './models';

const TOKEN_KEY = 'workspace-token';

/**
 * Authentication state as signals. The token lives in sessionStorage: it is
 * scoped to the tab and gone when the tab closes (frontend spec §3.4).
 */
@Injectable({ providedIn: 'root' })
export class AuthStore {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  private readonly tokenSignal = signal<string | null>(sessionStorage.getItem(TOKEN_KEY));
  private readonly userSignal = signal<CurrentUser | null>(null);

  readonly token = this.tokenSignal.asReadonly();
  readonly user = this.userSignal.asReadonly();
  readonly isAuthenticated = computed(() => this.tokenSignal() !== null);
  readonly canAssign = computed(() => this.userSignal()?.can_assign ?? false);
  readonly isAdmin = computed(() => this.userSignal()?.role === 'admin');

  async login(email: string, password: string): Promise<void> {
    const { token } = await this.api.issueToken(email, password);
    this.setToken(token);
    await this.loadUser();
  }

  /** Loads the profile once per session; returns false if the token is no longer valid. */
  async ensureUser(): Promise<boolean> {
    if (!this.tokenSignal()) return false;
    if (this.userSignal()) return true;

    try {
      await this.loadUser();
      return true;
    } catch {
      this.clear();
      return false;
    }
  }

  async refreshUser(): Promise<void> {
    if (this.tokenSignal()) await this.loadUser();
  }

  async logout(): Promise<void> {
    try {
      await this.api.revokeToken();
    } finally {
      this.clear();
      await this.router.navigateByUrl('/login');
    }
  }

  /** Forget the session locally (expired/revoked token). */
  clear(): void {
    sessionStorage.removeItem(TOKEN_KEY);
    this.tokenSignal.set(null);
    this.userSignal.set(null);
  }

  private async loadUser(): Promise<void> {
    this.userSignal.set(await this.api.me());
  }

  private setToken(token: string): void {
    sessionStorage.setItem(TOKEN_KEY, token);
    this.tokenSignal.set(token);
  }
}
