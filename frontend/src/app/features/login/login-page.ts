import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthStore } from '../../core/auth.store';
import { ApiError } from '../../core/models';

interface DemoAccount {
  label: string;
  email: string;
}

@Component({
  selector: 'app-login-page',
  imports: [ReactiveFormsModule],
  template: `
    <div class="grid min-h-dvh lg:grid-cols-2">
      <section class="hidden flex-col justify-between bg-indigo-600 p-12 text-white lg:flex">
        <div class="flex items-center gap-3 text-lg font-semibold">
          <img src="/images/favicon.svg" alt="" class="size-9 rounded-lg ring-2 ring-white/30" /> Lead CRM
        </div>
        <div class="max-w-md">
          <h1 class="text-4xl font-semibold leading-tight tracking-tight">Every lead, the right agent, on time.</h1>
          <p class="mt-4 text-indigo-100">
            Fair Round Robin assignment with daily capacity, automatic escalation after 30 minutes,
            and a full audit trail. This workspace runs entirely on the public REST API.
          </p>
        </div>
        <p class="text-sm text-indigo-200">Laravel 12 · Filament 5 · Angular 22</p>
      </section>

      <section class="flex items-center justify-center p-6 sm:p-12">
        <div class="w-full max-w-sm">
          <h2 class="text-2xl font-semibold tracking-tight">Sign in</h2>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Use one of the demo accounts below.</p>

          <form class="mt-8 space-y-5" [formGroup]="form" (ngSubmit)="submit()">
            <div>
              <label class="label" for="email">Email</label>
              <input id="email" type="email" class="input" formControlName="email" autocomplete="username" />
            </div>
            <div>
              <label class="label" for="password">Password</label>
              <input id="password" type="password" class="input" formControlName="password" autocomplete="current-password" />
            </div>

            @if (error()) {
              <p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" role="alert">
                {{ error() }}
              </p>
            }

            <button type="submit" class="btn-primary w-full" [disabled]="form.invalid || busy()">
              {{ busy() ? 'Signing in…' : 'Sign in' }}
            </button>
          </form>

          <div class="mt-8">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
              Demo accounts · password "password"
            </p>
            <div class="mt-3 grid gap-2">
              @for (account of demoAccounts; track account.email) {
                <button type="button" class="btn-outline justify-between text-left" (click)="useDemo(account)">
                  <span>{{ account.label }}</span>
                  <span class="text-xs text-slate-500 dark:text-slate-400">{{ account.email }}</span>
                </button>
              }
            </div>
          </div>
        </div>
      </section>
    </div>
  `,
})
export class LoginPage {
  private readonly auth = inject(AuthStore);
  private readonly router = inject(Router);

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly demoAccounts: DemoAccount[] = [
    { label: 'Admin', email: 'admin@example.com' },
    { label: 'Sales Manager', email: 'manager.cairo@example.com' },
    { label: 'Sales Agent', email: 'agent1.cairo@example.com' },
  ];

  protected readonly form = inject(NonNullableFormBuilder).group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });

  protected useDemo(account: DemoAccount): void {
    this.form.setValue({ email: account.email, password: 'password' });
    void this.submit();
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.busy()) return;
    this.busy.set(true);
    this.error.set(null);

    try {
      const { email, password } = this.form.getRawValue();
      await this.auth.login(email, password);
      await this.router.navigateByUrl('/');
    } catch (e) {
      this.error.set(e instanceof ApiError ? (e.errors['email']?.[0] ?? e.message) : 'Sign in failed.');
    } finally {
      this.busy.set(false);
    }
  }
}
