import { Injectable, signal } from '@angular/core';

export interface Toast {
  id: number;
  kind: 'success' | 'error';
  title: string;
  body?: string;
}

@Injectable({ providedIn: 'root' })
export class ToastService {
  private nextId = 1;
  readonly toasts = signal<Toast[]>([]);

  success(title: string, body?: string): void {
    this.push({ kind: 'success', title, body });
  }

  error(title: string, body?: string): void {
    this.push({ kind: 'error', title, body });
  }

  dismiss(id: number): void {
    this.toasts.update((list) => list.filter((toast) => toast.id !== id));
  }

  private push(toast: Omit<Toast, 'id'>): void {
    const id = this.nextId++;
    this.toasts.update((list) => [...list, { ...toast, id }]);
    setTimeout(() => this.dismiss(id), toast.kind === 'error' ? 8000 : 4000);
  }
}
