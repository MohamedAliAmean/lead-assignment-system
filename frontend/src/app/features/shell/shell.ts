import { Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthStore } from '../../core/auth.store';
import { ThemeService } from '../../core/theme.service';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Icon],
  template: `
    <div class="min-h-dvh">
      <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/80 backdrop-blur dark:border-white/10 dark:bg-slate-950/80">
        <div class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6">
          <a routerLink="/" class="flex items-center gap-2.5 font-semibold">
            <img src="/images/favicon.svg" alt="" class="size-8" />
            <span class="hidden sm:inline">Lead CRM <span class="font-normal text-slate-400">· Workspace</span></span>
          </a>

          <nav class="flex items-center gap-1" aria-label="Main">
            <a routerLink="/" [routerLinkActiveOptions]="{ exact: true }" routerLinkActive="nav-active" class="btn-ghost">
              <app-icon name="dashboard" size="size-4" /> Dashboard
            </a>
            <a routerLink="/leads" routerLinkActive="nav-active" class="btn-ghost">
              <app-icon name="users" size="size-4" /> Leads
            </a>
          </nav>

          <div class="ml-auto flex items-center gap-2">
            @if (auth.user(); as user) {
              <div class="hidden text-right leading-tight md:block">
                <p class="text-sm font-medium">{{ user.name }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                  {{ user.role_label }}@if (user.team) { · {{ user.team.name }} }
                </p>
              </div>
            }
            <button type="button" class="btn-ghost px-2.5" (click)="theme.toggle()"
                    [attr.aria-label]="theme.dark() ? 'Switch to light mode' : 'Switch to dark mode'">
              <app-icon [name]="theme.dark() ? 'sun' : 'moon'" />
            </button>
            <button type="button" class="btn-ghost px-2.5" (click)="auth.logout()" aria-label="Sign out">
              <app-icon name="logout" />
            </button>
          </div>
        </div>
      </header>

      <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <router-outlet />
      </main>
    </div>
  `,
})
export class Shell {
  protected readonly auth = inject(AuthStore);
  protected readonly theme = inject(ThemeService);
}
