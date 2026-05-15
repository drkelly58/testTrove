import { ref } from 'vue';

export type ThemeMode = 'dark' | 'light';

export const THEME_STORAGE_KEY = 'testtrove.theme' as const;

export const theme = ref<ThemeMode>('dark');

const THEME_COLOR: Record<ThemeMode, string> = {
  dark: '#1a2b3c',
  light: '#f8fafc',
};

export function readStoredTheme(): ThemeMode {
  try {
    const raw = localStorage.getItem(THEME_STORAGE_KEY);
    if (raw === 'light' || raw === 'dark') {
      return raw;
    }
  } catch {
    /* ignore */
  }
  return 'dark';
}

export function writeStoredTheme(mode: ThemeMode) {
  try {
    localStorage.setItem(THEME_STORAGE_KEY, mode);
  } catch {
    /* ignore */
  }
}

/** Apply theme to the document (call before first paint when possible). */
export function applyTheme(mode: ThemeMode) {
  theme.value = mode;
  const root = document.documentElement;
  root.setAttribute('data-theme', mode);
  root.style.colorScheme = mode;
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) {
    meta.setAttribute('content', THEME_COLOR[mode]);
  }
}

export function bootstrapThemeFromStorage() {
  applyTheme(readStoredTheme());
}
