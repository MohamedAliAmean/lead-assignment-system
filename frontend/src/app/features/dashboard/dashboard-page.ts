import { DecimalPipe } from '@angular/common';
import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthStore } from '../../core/auth.store';
import { DashboardStats, LEAD_STATUSES, LeadStatus, STATUS_LABELS } from '../../core/models';
import { StatusBadge } from '../../shared/badges';
import { Icon, IconName } from '../../shared/icon';
import { RelativeTimePipe } from '../../shared/relative-time';

interface Card {
  label: string;
  value: number;
  hint: string;
  icon: IconName;
  tone: string;
  query?: Record<string, string>;
}

const STATUS_BAR: Record<LeadStatus, string> = {
  new: 'bg-sky-500',
  contacted: 'bg-amber-500',
  qualified: 'bg-indigo-500',
  unqualified: 'bg-slate-400',
  won: 'bg-emerald-500',
  lost: 'bg-rose-500',
};

/**
 * Dashboard backed by GET /api/v1/dashboard/stats: the same scoped numbers as
 * the admin panel widgets (one shared query class on the server).
 */
@Component({
  selector: 'app-dashboard-page',
  imports: [DecimalPipe, RouterLink, Icon, StatusBadge, RelativeTimePipe],
  template: `
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">Good to see you, {{ firstName() }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ scopeLabel() }}</p>
      </div>
      @if (auth.user()?.capacity_today; as capacity) {
        <div class="card flex items-center gap-4 px-4 py-3">
          <div class="text-sm">
            <p class="font-medium">Your capacity today</p>
            <p class="text-slate-500 dark:text-slate-400">{{ capacity.used }} of {{ capacity.capacity }} leads received</p>
          </div>
          <div class="h-2 w-28 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10" role="progressbar"
               [attr.aria-valuenow]="capacity.used" aria-valuemin="0" [attr.aria-valuemax]="capacity.capacity"
               aria-label="Capacity used today">
            <div class="h-full rounded-full bg-indigo-500" [style.width.%]="percent(capacity.used, capacity.capacity)"></div>
          </div>
        </div>
      }
    </div>

    @if (loading()) {
      <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @for (i of [1, 2, 3, 4]; track i) {
          <div class="card h-28 animate-pulse"></div>
        }
      </div>
    } @else if (stats(); as s) {
      <div class="mt-8 grid gap-4 sm:grid-cols-2" [class.lg:grid-cols-5]="cards().length === 5" [class.lg:grid-cols-4]="cards().length === 4">
        @for (card of cards(); track card.label) {
          <a class="card block p-5 transition hover:shadow-md" routerLink="/leads" [queryParams]="card.query ?? {}">
            <div class="flex items-center justify-between">
              <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ card.label }}</p>
              <span class="rounded-lg p-1.5" [class]="card.tone"><app-icon [name]="card.icon" size="size-4" /></span>
            </div>
            <p class="mt-3 text-3xl font-semibold tabular-nums tracking-tight">{{ card.value | number }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ card.hint }}</p>
          </a>
        }
      </div>

      <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="card p-5 lg:col-span-2" aria-labelledby="trend-title">
          <h2 id="trend-title" class="text-sm font-semibold">New leads · last 14 days</h2>
          <div class="mt-8 flex h-48 items-end gap-1.5">
            @for (day of trend(); track day.date) {
              <div class="group relative flex h-full flex-1 flex-col justify-end">
                <div class="rounded-t-md bg-indigo-500/80 transition group-hover:bg-indigo-500" [style.height.%]="day.height"
                     role="img" [attr.aria-label]="day.label + ': ' + day.count + ' leads'"></div>
                <span class="pointer-events-none absolute -top-7 left-1/2 hidden -translate-x-1/2 whitespace-nowrap rounded bg-slate-900 px-1.5 py-0.5 text-xs text-white group-hover:block dark:bg-white dark:text-slate-900">
                  {{ day.label }} · {{ day.count | number }}
                </span>
              </div>
            }
          </div>
          <div class="mt-2 flex gap-1.5 text-[10px] text-slate-400">
            @for (day of trend(); track day.date) {
              <span class="flex-1 text-center">{{ day.short }}</span>
            }
          </div>
        </section>

        <section class="card p-5" aria-labelledby="pipeline-title">
          <h2 id="pipeline-title" class="text-sm font-semibold">Pipeline by status</h2>
          <div class="mt-4 flex h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
            @for (row of pipeline(); track row.status) {
              <div [class]="row.bar" [style.width.%]="row.percent" [title]="row.label"></div>
            }
          </div>
          <ul class="mt-5 space-y-2.5">
            @for (row of pipeline(); track row.status) {
              <li class="flex items-center justify-between text-sm">
                <span class="flex items-center gap-2"><span class="size-2.5 rounded-full" [class]="row.bar"></span>{{ row.label }}</span>
                <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ row.count | number }}</span>
              </li>
            }
          </ul>
        </section>
      </div>

      <div class="mt-6 grid gap-6" [class.lg:grid-cols-3]="s.capacity_today.length > 0">
        <section class="card overflow-hidden lg:col-span-2" aria-labelledby="attention-title">
          <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-white/10">
            <h2 id="attention-title" class="text-sm font-semibold">Needs attention · most overdue</h2>
            <a routerLink="/leads" [queryParams]="{ overdue: 1 }" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
              View all
            </a>
          </div>
          @if (s.needs_attention.length === 0) {
            <p class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">Nothing overdue. Nice work.</p>
          } @else {
            <ul class="divide-y divide-slate-100 dark:divide-white/5">
              @for (lead of s.needs_attention; track lead.id) {
                <li class="flex items-center gap-4 px-5 py-3">
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ lead.name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ lead.assigned_user?.name ?? 'Unassigned' }} · {{ lead.phone }}</p>
                  </div>
                  <app-status-badge [status]="lead.status" />
                  <span class="w-28 text-right text-xs font-medium text-rose-600 dark:text-rose-400">{{ lead.next_follow_up_at | relativeTime }}</span>
                </li>
              }
            </ul>
          }
        </section>

        @if (s.capacity_today.length > 0) {
          <section class="card p-5" aria-labelledby="capacity-title">
            <h2 id="capacity-title" class="text-sm font-semibold">Capacity today</h2>
            <ul class="mt-4 space-y-4">
              @for (agent of s.capacity_today; track agent.id) {
                <li>
                  <div class="flex justify-between text-sm">
                    <span class="font-medium">{{ agent.name }}</span>
                    <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ agent.used }} / {{ agent.capacity }}</span>
                  </div>
                  <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                    <div class="h-full rounded-full" [style.width.%]="percent(agent.used, agent.capacity)"
                         [class]="agent.remaining === 0 ? 'bg-rose-500' : percent(agent.used, agent.capacity) >= 75 ? 'bg-amber-500' : 'bg-indigo-500'"></div>
                  </div>
                </li>
              }
            </ul>
          </section>
        }
      </div>
    } @else if (failed()) {
      <div class="card mt-8 p-8 text-center">
        <p class="font-medium">Could not load the dashboard.</p>
        <button type="button" class="btn-outline mt-4" (click)="load()">Try again</button>
      </div>
    }
  `,
})
export class DashboardPage implements OnInit {
  protected readonly auth = inject(AuthStore);
  private readonly api = inject(ApiService);

  protected readonly stats = signal<DashboardStats | null>(null);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);

  protected readonly firstName = computed(() => this.auth.user()?.name.split(' ')[0] ?? '');
  protected readonly scopeLabel = computed(() => {
    const user = this.auth.user();
    if (user?.role === 'admin') return 'Company-wide view of every lead.';
    if (user?.role === 'sales_manager') return `Leads of your team${user.team ? ' · ' + user.team.name : ''}.`;
    return 'Leads assigned to you.';
  });

  protected readonly cards = computed<Card[]>(() => {
    const s = this.stats()?.summary;
    if (!s) return [];
    const cards: Card[] = [
      { label: 'Open leads', value: s.open, hint: 'New, contacted or qualified', icon: 'inbox', tone: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300' },
      { label: 'Overdue', value: s.overdue, hint: 'Follow-up time has passed', icon: 'clock', tone: 'bg-rose-50 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300', query: { overdue: '1' } },
      { label: 'New today', value: s.new_today, hint: 'Created since midnight', icon: 'sparkles', tone: 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300', query: { status: 'new' } },
      { label: 'Escalated today', value: s.escalated_today, hint: 'Left "New" for 30+ minutes', icon: 'bell', tone: 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300' },
    ];
    if (s.unassigned !== null) {
      cards.push({ label: 'Unassigned', value: s.unassigned, hint: 'No agent had capacity', icon: 'userPlus', tone: 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300', query: { unassigned: '1' } });
    }
    return cards;
  });

  protected readonly trend = computed(() => {
    const series = this.stats()?.created_per_day ?? {};
    const max = Math.max(1, ...Object.values(series));
    return Object.entries(series).map(([date, count]) => {
      const day = new Date(`${date}T00:00:00`);
      return {
        date,
        count,
        height: Math.max(2, (count / max) * 100),
        label: day.toLocaleDateString('en', { month: 'short', day: 'numeric' }),
        short: day.toLocaleDateString('en', { day: 'numeric' }),
      };
    });
  });

  protected readonly pipeline = computed(() => {
    const breakdown = this.stats()?.status_breakdown;
    if (!breakdown) return [];
    const total = Math.max(1, LEAD_STATUSES.reduce((sum, status) => sum + breakdown[status], 0));
    return LEAD_STATUSES.map((status) => ({
      status,
      label: STATUS_LABELS[status],
      count: breakdown[status],
      percent: (breakdown[status] / total) * 100,
      bar: STATUS_BAR[status],
    }));
  });

  ngOnInit(): void {
    void this.load();
  }

  protected percent(used: number, capacity: number): number {
    return capacity > 0 ? Math.min(100, (used / capacity) * 100) : 100;
  }

  protected async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(false);
    try {
      this.stats.set(await this.api.stats());
      void this.auth.refreshUser();
    } catch {
      this.failed.set(true);
    } finally {
      this.loading.set(false);
    }
  }
}
