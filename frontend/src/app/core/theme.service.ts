import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class ThemeService {
  readonly dark = signal(document.documentElement.classList.contains('dark'));

  toggle(): void {
    const dark = !this.dark();
    this.dark.set(dark);
    document.documentElement.classList.toggle('dark', dark);
    localStorage.setItem('workspace-theme', dark ? 'dark' : 'light');
  }
}
