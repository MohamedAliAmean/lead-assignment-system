import { Component, inject } from '@angular/core';
import { ToastService } from '../core/toast.service';
import { Icon } from './icon';

@Component({
  selector: 'app-toaster',
  imports: [Icon],
  template: `
    <div class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4 sm:items-end" aria-live="polite">
      @for (toast of toasts.toasts(); track toast.id) {
        <div class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border bg-white p-4 shadow-lg dark:bg-slate-900"
             [class]="toast.kind === 'error' ? 'border-rose-200 dark:border-rose-500/30' : 'border-emerald-200 dark:border-emerald-500/30'"
             role="status">
          <app-icon [name]="toast.kind === 'error' ? 'alert' : 'check'"
                    [class]="toast.kind === 'error' ? 'text-rose-500' : 'text-emerald-500'" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">{{ toast.title }}</p>
            @if (toast.body) {
              <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ toast.body }}</p>
            }
          </div>
          <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" (click)="toasts.dismiss(toast.id)" aria-label="Dismiss">
            <app-icon name="close" size="size-4" />
          </button>
        </div>
      }
    </div>
  `,
})
export class Toaster {
  protected readonly toasts = inject(ToastService);
}
