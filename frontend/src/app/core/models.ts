/**
 * Types mirroring the OpenAPI schemas served at /api/documentation.
 */
export type Role = 'admin' | 'sales_manager' | 'sales_agent';
export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'unqualified' | 'won' | 'lost';
export type Priority = 1 | 2 | 3;

export const LEAD_STATUSES: readonly LeadStatus[] = ['new', 'contacted', 'qualified', 'unqualified', 'won', 'lost'];

export const STATUS_LABELS: Record<LeadStatus, string> = {
  new: 'New',
  contacted: 'Contacted',
  qualified: 'Qualified',
  unqualified: 'Unqualified',
  won: 'Won',
  lost: 'Lost',
};

export interface UserSummary {
  id: number;
  name: string;
}

export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  role: Role;
  role_label: string;
  team: UserSummary | null;
  can_assign: boolean;
  capacity_today: { capacity: number; used: number; remaining: number } | null;
}

export interface Lead {
  id: number;
  name: string;
  phone: string;
  source: string;
  external_ref: string | null;
  priority: Priority;
  priority_label: string;
  status: LeadStatus;
  assigned_to: number | null;
  assigned_user?: UserSummary | null;
  assigned_at: string | null;
  next_follow_up_at: string | null;
  is_overdue: boolean;
  created_at: string;
  updated_at: string;
}

export interface StatusLogEntry {
  id: number;
  old_status: LeadStatus | null;
  new_status: LeadStatus;
  changed_by?: UserSummary | null;
  changed_at: string;
}

export interface CursorPage<T> {
  data: T[];
  meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null };
}

export interface AgentCapacity {
  id: number;
  name: string;
  capacity: number;
  used: number;
  remaining: number;
}

export interface DashboardStats {
  summary: { open: number; overdue: number; new_today: number; unassigned: number | null; escalated_today: number };
  created_per_day: Record<string, number>;
  status_breakdown: Record<LeadStatus, number>;
  capacity_today: AgentCapacity[];
  needs_attention: Lead[];
}

export interface LeadFilters {
  mine: boolean;
  unassigned: boolean;
  overdue: boolean;
  highPriority: boolean;
  status: LeadStatus | '';
  search: string;
}

/** The API's single error envelope: { message, code, errors?, context? }. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly errors: Record<string, string[]> = {},
    readonly context: Record<string, unknown> = {},
  ) {
    super(message);
  }
}
