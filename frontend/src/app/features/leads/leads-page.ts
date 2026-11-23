import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthStore } from '../../core/auth.store';
import { ApiError, LEAD_STATUSES, Lead, LeadFilters, LeadStatus, STATUS_LABELS } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { PriorityBadge, StatusBadge } from '../../shared/badges';
import { Icon } from '../../shared/icon';
import { RelativeTimePipe } from '../../shared/relative-time';
import { BulkAssignDialog } from './bulk-assign-dialog';
import { LeadDrawer } from './lead-drawer';

type ToggleFilter = 'mine' | 'unassigned' | 'overdue' | 'highPriority';

/**
 * Lead list on GET /api/v1/leads: server-side filters, index-friendly search
 * and cursor pagination ("Load more"), exactly like the admin panel.
 */
@Component({
  selector: 'app-leads-page',
  imports: [FormsModule, Icon, StatusBadge, PriorityBadge, RelativeTimePipe, LeadDrawer, BulkAssignDialog],
  template: `
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">Leads</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Newest first · only leads you are allowed to see.</p>
      </div>
      @if (auth.canAssign() && selected().size > 0) {
        <button type="button" class="btn-primary" (click)="assigning.set(true)">
          <app-icon name="userPlus" size="size-4" /> Assign {{ selected().size }} selected
        </button>
      }
    </div>

    <div class="card mt-6 p-4">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
        <div class="relative flex-1">
          <app-icon name="search" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input type="search" class="input pl-9" placeholder="Search name or phone prefix…" aria-label="Search leads"
                 [ngModel]="filters().search" (ngModelChange)="search$.next($event)" />
        </div>
        <select class="input lg:w-44" aria-label="Status" [ngModel]="filters().status" (ngModelChange)="setStatus($event)">
          <option value="">All statuses</option>
          @for (status of statuses; track status) {
            <option [value]="status">{{ labels[status] }}</option>
          }
        </select>
      </div>
      <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="Quick filters">
        @for (chip of chips(); track chip.key) {
          <button type="button" class="chip" [attr.aria-pressed]="filters()[chip.key]"
                  [class]="filters()[chip.key]
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300'
                    : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5'"
                  (click)="toggle(chip.key)">
            {{ chip.label }}
          </button>
        }
      </div>
    </div>

    <div class="card mt-4 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="border-b border-slate-200 bg-slate-50/60 text-xs uppercase tracking-wide text-slate-500 dark:border-white/10 dark:bg-white/[0.02] dark:text-slate-400">
            <tr>
              @if (auth.canAssign()) {
                <th class="w-10 px-4 py-3">
                  <input type="checkbox" class="accent-indigo-600" aria-label="Select all loaded leads"
                         [checked]="allSelected()" (change)="toggleAll()" />
                </th>
              }
              <th class="px-4 py-3 font-medium">Name</th>
              <th class="px-4 py-3 font-medium">Phone</th>
              <th class="px-4 py-3 font-medium">Source</th>
              <th class="px-4 py-3 font-medium">Status</th>
              <th class="px-4 py-3 font-medium">Priority</th>
              <th class="px-4 py-3 font-medium">Assigned to</th>
              <th class="px-4 py-3 font-medium">Follow-up</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-white/5">
            @for (lead of leads(); track lead.id) {
              <tr class="cursor-pointer transition hover:bg-slate-50 dark:hover:bg-white/[0.03]" (click)="open(lead)"
                  [class.bg-indigo-50]="selected().has(lead.id)" [class.dark:bg-indigo-500/5]="selected().has(lead.id)">
                @if (auth.canAssign()) {
                  <td class="px-4 py-3" (click)="$event.stopPropagation()">
                    <input type="checkbox" class="accent-indigo-600" [attr.aria-label]="'Select ' + lead.name"
                           [checked]="selected().has(lead.id)" (change)="toggleOne(lead.id)" />
                  </td>
                }
                <td class="px-4 py-3 font-medium">
                  <button type="button" class="text-left hover:text-indigo-600 dark:hover:text-indigo-400" (click)="open(lead); $event.stopPropagation()">
                    {{ lead.name }}
                  </button>
                </td>
                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ lead.phone }}</td>
                <td class="px-4 py-3 capitalize text-slate-600 dark:text-slate-300">{{ lead.source.replace('_', ' ') }}</td>
                <td class="px-4 py-3"><app-status-badge [status]="lead.status" /></td>
                <td class="px-4 py-3"><app-priority-badge [priority]="lead.priority" /></td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ lead.assigned_user?.name ?? 'Unassigned' }}</td>
                <td class="whitespace-nowrap px-4 py-3" [class]="lead.is_overdue ? 'font-medium text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-300'">
                  {{ lead.is_overdue ? 'Overdue · ' : '' }}{{ lead.next_follow_up_at | relativeTime }}
                </td>
              </tr>
            } @empty {
              @if (!loading()) {
                <tr><td colspan="8" class="px-4 py-16 text-center text-slate-500 dark:text-slate-400">No leads match these filters.</td></tr>
              }
            }
          </tbody>
        </table>
      </div>

      <div class="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm dark:border-white/10">
        <span class="text-slate-500 dark:text-slate-400">{{ leads().length }} loaded</span>
        @if (loading()) {
          <span class="text-slate-500">Loading…</span>
        } @else if (nextCursor()) {
          <button type="button" class="btn-outline" (click)="loadMore()">Load more</button>
        } @else if (leads().length > 0) {
          <span class="text-slate-400">End of list</span>
        }
      </div>
    </div>

    @if (active(); as lead) {
      <app-lead-drawer [lead]="lead" (closed)="active.set(null)" (updated)="onUpdated($event)" />
    }
    @if (assigning()) {
      <app-bulk-assign-dialog [leadIds]="selectedIds()" (closed)="assigning.set(false)" (assigned)="onAssigned($event)" />
    }
  `,
})
export class LeadsPage implements OnInit {
  protected readonly auth = inject(AuthStore);
  private readonly api = inject(ApiService);
  private readonly toasts = inject(ToastService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly statuses = LEAD_STATUSES;
  protected readonly labels = STATUS_LABELS;

  protected readonly filters = signal<LeadFilters>({ mine: false, unassigned: false, overdue: false, highPriority: false, status: '', search: '' });
  protected readonly leads = signal<Lead[]>([]);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly loading = signal(false);
  protected readonly selected = signal<ReadonlySet<number>>(new Set());
  protected readonly active = signal<Lead | null>(null);
  protected readonly assigning = signal(false);
  protected readonly search$ = new Subject<string>();

  /** Ignores responses from superseded requests (filters changed meanwhile). */
  private requestId = 0;

  protected readonly chips = computed(() => {
    const chips: { key: ToggleFilter; label: string }[] = [{ key: 'mine', label: 'My leads' }];
    if (this.auth.isAdmin()) chips.push({ key: 'unassigned', label: 'Unassigned' });
    chips.push({ key: 'overdue', label: 'Overdue' }, { key: 'highPriority', label: 'High priority' });
    return chips;
  });

  protected readonly selectedIds = computed(() => [...this.selected()]);
  protected readonly allSelected = computed(() => this.leads().length > 0 && this.leads().every((lead) => this.selected().has(lead.id)));

  ngOnInit(): void {
    const query = this.route.snapshot.queryParamMap;
    const status = query.get('status') ?? '';
    this.filters.set({
      mine: query.has('mine'),
      unassigned: query.has('unassigned'),
      overdue: query.has('overdue'),
      highPriority: query.has('high'),
      status: (LEAD_STATUSES as readonly string[]).includes(status) ? (status as LeadStatus) : '',
      search: query.get('q') ?? '',
    });

    this.search$.pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef)).subscribe((search) => {
      this.filters.update((f) => ({ ...f, search }));
      void this.reload();
    });

    void this.reload();
  }

  protected toggle(key: ToggleFilter): void {
    this.filters.update((f) => ({ ...f, [key]: !f[key] }));
    void this.reload();
  }

  protected setStatus(status: LeadStatus | ''): void {
    this.filters.update((f) => ({ ...f, status }));
    void this.reload();
  }

  protected toggleOne(id: number): void {
    this.selected.update((set) => {
      const next = new Set(set);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  protected toggleAll(): void {
    this.selected.set(this.allSelected() ? new Set() : new Set(this.leads().map((lead) => lead.id)));
  }

  protected open(lead: Lead): void {
    this.active.set(lead);
  }

  protected onUpdated(updated: Lead): void {
    this.leads.update((list) => list.map((lead) => (lead.id === updated.id ? { ...lead, ...updated } : lead)));
    this.active.set({ ...this.active()!, ...updated });
  }

  protected onAssigned(count: number): void {
    this.assigning.set(false);
    this.selected.set(new Set());
    this.toasts.success(`${count} lead(s) assigned`, count === 0 ? 'They were already assigned to that agent.' : undefined);
    void this.reload();
  }

  protected async loadMore(): Promise<void> {
    await this.fetch(this.nextCursor());
  }

  private async reload(): Promise<void> {
    this.syncUrl();
    this.selected.set(new Set());
    await this.fetch(null);
  }

  private async fetch(cursor: string | null): Promise<void> {
    const id = ++this.requestId;
    this.loading.set(true);
    if (!cursor) this.leads.set([]);

    try {
      const page = await this.api.leads(this.filters(), cursor);
      if (id !== this.requestId) return;
      this.leads.update((list) => (cursor ? [...list, ...page.data] : page.data));
      this.nextCursor.set(page.meta.next_cursor);
    } catch (e) {
      if (id === this.requestId) this.toasts.error('Could not load leads', e instanceof ApiError ? e.message : undefined);
    } finally {
      if (id === this.requestId) this.loading.set(false);
    }
  }

  /** Keeps filters in the URL, so a filtered view can be shared or reloaded. */
  private syncUrl(): void {
    const f = this.filters();
    void this.router.navigate([], {
      replaceUrl: true,
      queryParams: {
        mine: f.mine ? 1 : null,
        unassigned: f.unassigned ? 1 : null,
        overdue: f.overdue ? 1 : null,
        high: f.highPriority ? 1 : null,
        status: f.status || null,
        q: f.search || null,
      },
    });
  }
}
