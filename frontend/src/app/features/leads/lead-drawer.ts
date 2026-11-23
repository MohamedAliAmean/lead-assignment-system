import { DatePipe } from '@angular/common';
import { Component, effect, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/api.service';
import { ApiError, LEAD_STATUSES, Lead, LeadStatus, STATUS_LABELS, StatusLogEntry } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { PriorityBadge, StatusBadge } from '../../shared/badges';
import { Icon } from '../../shared/icon';
import { RelativeTimePipe } from '../../shared/relative-time';

/**
 * Lead details + status timeline + status change (PATCH /leads/{id}/status).
 * The server writes the audit entry; the timeline is re-read afterwards.
 */
@Component({
  selector: 'app-lead-drawer',
  imports: [DatePipe, FormsModule, Icon, StatusBadge, PriorityBadge, RelativeTimePipe],
  host: { '(document:keydown.escape)': 'closed.emit()' },
  template: `
    <div class="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-sm" (click)="closed.emit()" aria-hidden="true"></div>
    <aside class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-2xl dark:bg-slate-900"
           role="dialog" aria-modal="true" aria-labelledby="drawer-title">
      <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-6 dark:border-white/10">
        <div class="min-w-0">
          <h2 id="drawer-title" class="text-lg font-semibold">{{ lead().name }}</h2>
          <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
            <app-icon name="phone" size="size-4" /> {{ lead().phone }}
          </p>
        </div>
        <button type="button" class="btn-ghost px-2" (click)="closed.emit()" aria-label="Close details">
          <app-icon name="close" />
        </button>
      </div>

      <div class="flex-1 space-y-8 overflow-y-auto p-6">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-5 text-sm">
          <div><dt class="text-slate-500 dark:text-slate-400">Status</dt><dd class="mt-1"><app-status-badge [status]="lead().status" /></dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">Priority</dt><dd class="mt-1"><app-priority-badge [priority]="lead().priority" /></dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">Assigned to</dt><dd class="mt-1 font-medium">{{ lead().assigned_user?.name ?? 'Unassigned' }}</dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">Source</dt><dd class="mt-1 font-medium capitalize">{{ lead().source.replace('_', ' ') }}</dd></div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">Next follow-up</dt>
            <dd class="mt-1 font-medium" [class.text-rose-600]="lead().is_overdue" [class.dark:text-rose-400]="lead().is_overdue">
              {{ lead().next_follow_up_at | relativeTime }}
            </dd>
          </div>
          <div><dt class="text-slate-500 dark:text-slate-400">Created</dt><dd class="mt-1 font-medium">{{ lead().created_at | date: 'MMM d, y, h:mm a' }}</dd></div>
        </dl>

        <form class="card p-4" (ngSubmit)="saveStatus()">
          <label class="label" for="status">Change status</label>
          <div class="flex gap-2">
            <select id="status" class="input" [(ngModel)]="nextStatus" name="status">
              @for (status of statuses; track status) {
                <option [value]="status">{{ labels[status] }}</option>
              }
            </select>
            <button type="submit" class="btn-primary shrink-0" [disabled]="saving() || nextStatus === lead().status">
              {{ saving() ? 'Saving…' : 'Save' }}
            </button>
          </div>
          <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Every change is recorded in the audit log below.</p>
        </form>

        <section aria-labelledby="history-title">
          <h3 id="history-title" class="text-sm font-semibold">Status history</h3>
          @if (historyLoading()) {
            <p class="mt-4 text-sm text-slate-500">Loading…</p>
          } @else {
            <ol class="relative mt-4 space-y-5 border-l border-slate-200 pl-5 dark:border-white/10">
              @for (entry of history(); track entry.id) {
                <li class="relative">
                  <span class="absolute -left-[26px] top-1 size-2.5 rounded-full bg-indigo-500 ring-4 ring-white dark:ring-slate-900"></span>
                  <div class="flex flex-wrap items-center gap-1.5 text-sm">
                    @if (entry.old_status) {
                      <app-status-badge [status]="entry.old_status" /> <span class="text-slate-400">→</span>
                    } @else {
                      <span class="text-slate-500 dark:text-slate-400">Created as</span>
                    }
                    <app-status-badge [status]="entry.new_status" />
                  </div>
                  <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ entry.changed_by?.name ?? 'System' }} · {{ entry.changed_at | date: 'MMM d, h:mm a' }}
                  </p>
                </li>
              }
            </ol>
          }
        </section>
      </div>
    </aside>
  `,
})
export class LeadDrawer {
  private readonly api = inject(ApiService);
  private readonly toasts = inject(ToastService);

  readonly lead = input.required<Lead>();
  readonly closed = output<void>();
  readonly updated = output<Lead>();

  protected readonly statuses = LEAD_STATUSES;
  protected readonly labels = STATUS_LABELS;
  protected readonly history = signal<StatusLogEntry[]>([]);
  protected readonly historyLoading = signal(true);
  protected readonly saving = signal(false);
  protected nextStatus: LeadStatus = 'new';

  constructor() {
    effect(() => {
      const lead = this.lead();
      this.nextStatus = lead.status;
      void this.loadHistory(lead.id);
    });
  }

  protected async saveStatus(): Promise<void> {
    this.saving.set(true);
    try {
      const updated = await this.api.changeStatus(this.lead().id, this.nextStatus);
      this.toasts.success('Status updated', `${updated.name} is now ${STATUS_LABELS[updated.status]}.`);
      this.updated.emit(updated);
    } catch (e) {
      this.toasts.error('Could not change status', e instanceof ApiError ? e.message : undefined);
    } finally {
      this.saving.set(false);
    }
  }

  private async loadHistory(id: number): Promise<void> {
    this.historyLoading.set(true);
    try {
      this.history.set(await this.api.history(id));
    } finally {
      this.historyLoading.set(false);
    }
  }
}
