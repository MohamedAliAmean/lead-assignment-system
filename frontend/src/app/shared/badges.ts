import { Component, computed, input } from '@angular/core';
import { LeadStatus, Priority, STATUS_LABELS } from '../core/models';

const STATUS_CLASSES: Record<LeadStatus, string> = {
  new: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/30',
  contacted: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30',
  qualified: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-400/30',
  unqualified: 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-white/5 dark:text-slate-300 dark:ring-white/15',
  won: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30',
  lost: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30',
};

@Component({
  selector: 'app-status-badge',
  template: `<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset" [class]="classes()">{{ label() }}</span>`,
})
export class StatusBadge {
  readonly status = input.required<LeadStatus>();
  protected readonly label = computed(() => STATUS_LABELS[this.status()]);
  protected readonly classes = computed(() => STATUS_CLASSES[this.status()]);
}

@Component({
  selector: 'app-priority-badge',
  template: `
    <span class="inline-flex items-center gap-1.5 text-xs font-medium" [class]="classes()">
      <span class="size-1.5 rounded-full bg-current"></span>{{ label() }}
    </span>
  `,
})
export class PriorityBadge {
  readonly priority = input.required<Priority>();
  protected readonly label = computed(() => ({ 1: 'Low', 2: 'Medium', 3: 'High' })[this.priority()]);
  protected readonly classes = computed(
    () => ({ 1: 'text-slate-500 dark:text-slate-400', 2: 'text-amber-600 dark:text-amber-400', 3: 'text-rose-600 dark:text-rose-400' })[this.priority()],
  );
}
