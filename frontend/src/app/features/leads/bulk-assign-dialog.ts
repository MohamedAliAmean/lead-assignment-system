import { Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/api.service';
import { AgentCapacity, ApiError } from '../../core/models';
import { Icon } from '../../shared/icon';

/**
 * Bulk assignment (POST /leads/bulk-assign). The agent list and remaining
 * capacity come from the same server rule that validates the request; the
 * server still has the final word (all-or-nothing, 409 on capacity).
 */
@Component({
  selector: 'app-bulk-assign-dialog',
  imports: [FormsModule, Icon],
  host: { '(document:keydown.escape)': 'closed.emit()' },
  template: `
    <div class="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-sm" (click)="closed.emit()" aria-hidden="true"></div>
    <div class="fixed inset-x-4 top-[15vh] z-50 mx-auto max-w-md" role="dialog" aria-modal="true" aria-labelledby="assign-title">
      <form class="card p-6 shadow-2xl" (ngSubmit)="submit()">
        <div class="flex items-start justify-between">
          <div>
            <h2 id="assign-title" class="text-lg font-semibold">Assign {{ leadIds().length }} lead(s)</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">All or nothing: if the agent lacks capacity, nothing changes.</p>
          </div>
          <button type="button" class="btn-ghost px-2" (click)="closed.emit()" aria-label="Close"><app-icon name="close" /></button>
        </div>

        <fieldset class="mt-6">
          <legend class="label">Agent</legend>
          @if (loading()) {
            <p class="text-sm text-slate-500">Loading agents…</p>
          } @else if (agents().length === 0) {
            <p class="text-sm text-slate-500">There are no agents you can assign to.</p>
          } @else {
            <div class="max-h-72 space-y-2 overflow-y-auto pr-1">
              @for (agent of agents(); track agent.id) {
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition"
                       [class]="selected() === agent.id ? 'border-indigo-500 bg-indigo-50/60 dark:bg-indigo-500/10' : 'border-slate-200 hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/5'">
                  <input type="radio" name="agent" class="accent-indigo-600" [value]="agent.id"
                         [checked]="selected() === agent.id" (change)="selected.set(agent.id)" />
                  <span class="flex-1 text-sm font-medium">{{ agent.name }}</span>
                  <span class="text-xs tabular-nums" [class]="agent.remaining >= leadIds().length ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                    {{ agent.remaining }} left today
                  </span>
                </label>
              }
            </div>
          }
        </fieldset>

        @if (warning()) {
          <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ warning() }}</p>
        }
        @if (error()) {
          <p class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" role="alert">{{ error() }}</p>
        }

        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="btn-outline" (click)="closed.emit()">Cancel</button>
          <button type="submit" class="btn-primary" [disabled]="!selected() || busy()">
            <app-icon name="userPlus" size="size-4" /> {{ busy() ? 'Assigning…' : 'Assign' }}
          </button>
        </div>
      </form>
    </div>
  `,
})
export class BulkAssignDialog implements OnInit {
  private readonly api = inject(ApiService);

  readonly leadIds = input.required<number[]>();
  readonly closed = output<void>();
  readonly assigned = output<number>();

  protected readonly agents = signal<AgentCapacity[]>([]);
  protected readonly loading = signal(true);
  protected readonly selected = signal<number | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly warning = computed(() => {
    const agent = this.agents().find((a) => a.id === this.selected());
    return agent && agent.remaining < this.leadIds().length
      ? `${agent.name} has only ${agent.remaining} slot(s) left today. The server will reject this unless some leads are already theirs.`
      : null;
  });

  async ngOnInit(): Promise<void> {
    try {
      this.agents.set(await this.api.assignableAgents());
    } finally {
      this.loading.set(false);
    }
  }

  protected async submit(): Promise<void> {
    const assignee = this.selected();
    if (!assignee) return;
    this.busy.set(true);
    this.error.set(null);
    try {
      this.assigned.emit(await this.api.bulkAssign(this.leadIds(), assignee));
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'Assignment failed.');
    } finally {
      this.busy.set(false);
    }
  }
}
