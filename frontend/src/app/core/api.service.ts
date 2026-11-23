import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import {
  AgentCapacity,
  CurrentUser,
  CursorPage,
  DashboardStats,
  Lead,
  LeadFilters,
  LeadStatus,
  StatusLogEntry,
} from './models';

/**
 * Thin typed client over the REST API (/api/v1). All authorization happens on
 * the server; this class only maps calls and types.
 */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/v1';

  issueToken(email: string, password: string): Promise<{ token: string }> {
    return firstValueFrom(
      this.http.post<{ token: string }>(`${this.base}/auth/tokens`, { email, password, device_name: 'workspace' }),
    );
  }

  revokeToken(): Promise<void> {
    return firstValueFrom(this.http.delete<void>(`${this.base}/auth/tokens/current`));
  }

  async me(): Promise<CurrentUser> {
    return (await firstValueFrom(this.http.get<{ data: CurrentUser }>(`${this.base}/me`))).data;
  }

  async stats(): Promise<DashboardStats> {
    return (await firstValueFrom(this.http.get<{ data: DashboardStats }>(`${this.base}/dashboard/stats`))).data;
  }

  leads(filters: LeadFilters, cursor: string | null, perPage = 25): Promise<CursorPage<Lead>> {
    let params = new HttpParams().set('per_page', perPage);
    if (filters.mine) params = params.set('filter[mine]', 1);
    if (filters.unassigned) params = params.set('filter[unassigned]', 1);
    if (filters.overdue) params = params.set('filter[overdue]', 1);
    if (filters.highPriority) params = params.set('filter[priority]', 'high');
    if (filters.status) params = params.set('filter[status]', filters.status);
    if (filters.search.trim()) params = params.set('search', filters.search.trim());
    if (cursor) params = params.set('cursor', cursor);

    return firstValueFrom(this.http.get<CursorPage<Lead>>(`${this.base}/leads`, { params }));
  }

  async lead(id: number): Promise<Lead> {
    return (await firstValueFrom(this.http.get<{ data: Lead }>(`${this.base}/leads/${id}`))).data;
  }

  async history(id: number): Promise<StatusLogEntry[]> {
    return (await firstValueFrom(this.http.get<CursorPage<StatusLogEntry>>(`${this.base}/leads/${id}/status-history`))).data;
  }

  async changeStatus(id: number, status: LeadStatus): Promise<Lead> {
    return (await firstValueFrom(this.http.patch<{ data: Lead }>(`${this.base}/leads/${id}/status`, { status }))).data;
  }

  async assignableAgents(): Promise<AgentCapacity[]> {
    return (await firstValueFrom(this.http.get<{ data: AgentCapacity[] }>(`${this.base}/assignable-agents`))).data;
  }

  async bulkAssign(leadIds: number[], assigneeId: number): Promise<number> {
    const response = await firstValueFrom(
      this.http.post<{ assigned: number }>(`${this.base}/leads/bulk-assign`, { lead_ids: leadIds, assignee_id: assigneeId }),
    );
    return response.assigned;
  }
}
