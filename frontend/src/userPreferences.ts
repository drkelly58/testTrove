import { ref } from 'vue';
import { apiFetch } from '@/api';
import type { AuthUser } from '@/authSession';
import { applyTheme, readStoredTheme, type ThemeMode, writeStoredTheme } from '@/theme';

/** Keys stored in users.preferences (JSON). */
export type UserPreferences = {
  default_project_id?: number | null;
  run_overview_single_expand?: boolean;
  theme?: ThemeMode;
  email_notify_run_assigned?: boolean;
  email_notify_run_completed?: boolean;
};

const LOCAL_DEFAULT_PROJECT_KEY = 'testtrove.defaultProjectId' as const;
const LOCAL_RUN_OVERVIEW_SINGLE_EXPAND_KEY = 'testtrove.runOverview.singleExpand' as const;

let serverBacked = false;
let cached: UserPreferences = {};

export const runOverviewSingleExpand = ref(true);

export const emailNotifyRunAssigned = ref(false);

export const emailNotifyRunCompleted = ref(false);

function readLocalDefaultProjectId(): number | null {
  try {
    const raw = localStorage.getItem(LOCAL_DEFAULT_PROJECT_KEY);
    if (raw == null || raw === '') {
      return null;
    }
    const n = parseInt(raw, 10);
    return Number.isFinite(n) ? n : null;
  } catch {
    return null;
  }
}

function readLocalRunOverviewSingleExpand(defaultValue: boolean): boolean {
  try {
    const raw = localStorage.getItem(LOCAL_RUN_OVERVIEW_SINGLE_EXPAND_KEY);
    if (raw === '1' || raw === 'true') {
      return true;
    }
    if (raw === '0' || raw === 'false') {
      return false;
    }
  } catch {
    /* ignore */
  }
  return defaultValue;
}

function writeLocalDefaultProjectId(id: number | null) {
  try {
    if (id === null) {
      localStorage.removeItem(LOCAL_DEFAULT_PROJECT_KEY);
    } else {
      localStorage.setItem(LOCAL_DEFAULT_PROJECT_KEY, String(id));
    }
  } catch {
    /* ignore */
  }
}

function writeLocalRunOverviewSingleExpand(value: boolean) {
  try {
    localStorage.setItem(LOCAL_RUN_OVERVIEW_SINGLE_EXPAND_KEY, value ? '1' : '0');
  } catch {
    /* ignore */
  }
}

function readLocalPreferences(): UserPreferences {
  const prefs: UserPreferences = {};
  const projectId = readLocalDefaultProjectId();
  if (projectId !== null) {
    prefs.default_project_id = projectId;
  }
  const rawExpand = localStorage.getItem(LOCAL_RUN_OVERVIEW_SINGLE_EXPAND_KEY);
  if (rawExpand !== null) {
    prefs.run_overview_single_expand = readLocalRunOverviewSingleExpand(true);
  }
  prefs.theme = readStoredTheme();
  return prefs;
}

function resolveTheme(prefs: UserPreferences): ThemeMode {
  return prefs.theme === 'light' ? 'light' : 'dark';
}

function applyToUi(prefs: UserPreferences) {
  runOverviewSingleExpand.value = prefs.run_overview_single_expand ?? true;
  emailNotifyRunAssigned.value = prefs.email_notify_run_assigned ?? false;
  emailNotifyRunCompleted.value = prefs.email_notify_run_completed ?? false;
  applyTheme(resolveTheme(prefs));
}

function isEmptyPrefs(prefs: UserPreferences): boolean {
  return Object.keys(prefs).length === 0;
}

async function patchOnServer(patch: UserPreferences): Promise<UserPreferences> {
  const res = await apiFetch('/api/auth/preferences', {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(patch),
  });
  const text = await res.text();
  if (!res.ok) {
    let msg = res.statusText;
    try {
      const j = JSON.parse(text) as { error?: string };
      if (j.error) {
        msg = j.error;
      }
    } catch {
      /* ignore */
    }
    throw new Error(msg);
  }
  const j = JSON.parse(text) as { data: { preferences: UserPreferences } };
  return j.data.preferences;
}

/** True when the active session should read/write preferences on the user record. */
export function preferencesAreServerBacked(): boolean {
  return serverBacked;
}

export function getDefaultProjectIdPreference(): number | null | undefined {
  if (serverBacked) {
    return cached.default_project_id;
  }
  return readLocalDefaultProjectId();
}

export function clearUserPreferencesState(): void {
  serverBacked = false;
  cached = {};
  applyToUi(readLocalPreferences());
}

/** Apply preferences from the authenticated user (session or login response). */
export function syncPreferencesFromAuthUser(user: AuthUser | null): void {
  if (!user) {
    clearUserPreferencesState();
    return;
  }
  serverBacked = true;
  cached = { ...(user.preferences ?? {}) };
  if (isEmptyPrefs(cached)) {
    const local = readLocalPreferences();
    if (!isEmptyPrefs(local)) {
      cached = { ...local };
      void patchOnServer(local)
        .then((merged) => {
          cached = merged;
        })
        .catch(() => {
          /* keep local merge in memory */
        });
    }
  }
  applyToUi(cached);
}

export async function setDefaultProjectIdPreference(id: number | null): Promise<void> {
  cached = { ...cached, default_project_id: id };
  if (serverBacked) {
    try {
      cached = await patchOnServer({ default_project_id: id });
    } catch {
      /* keep in-memory value */
    }
  } else {
    writeLocalDefaultProjectId(id);
  }
}

export function setRunOverviewSingleExpand(value: boolean): void {
  runOverviewSingleExpand.value = value;
  cached = { ...cached, run_overview_single_expand: value };
  if (serverBacked) {
    void patchOnServer({ run_overview_single_expand: value })
      .then((merged) => {
        cached = merged;
      })
      .catch(() => {
        /* keep in-memory value */
      });
  } else {
    writeLocalRunOverviewSingleExpand(value);
  }
}

export function setThemePreference(mode: ThemeMode): void {
  applyTheme(mode);
  cached = { ...cached, theme: mode };
  if (serverBacked) {
    void patchOnServer({ theme: mode })
      .then((merged) => {
        cached = merged;
        applyToUi(merged);
      })
      .catch(() => {
        /* keep in-memory value */
      });
  } else {
    writeStoredTheme(mode);
  }
}

export function setEmailNotifyRunAssigned(value: boolean): void {
  emailNotifyRunAssigned.value = value;
  cached = { ...cached, email_notify_run_assigned: value };
  if (serverBacked) {
    void patchOnServer({ email_notify_run_assigned: value })
      .then((merged) => {
        cached = merged;
        applyToUi(merged);
      })
      .catch(() => {
        /* keep in-memory value */
      });
  }
}

export function setEmailNotifyRunCompleted(value: boolean): void {
  emailNotifyRunCompleted.value = value;
  cached = { ...cached, email_notify_run_completed: value };
  if (serverBacked) {
    void patchOnServer({ email_notify_run_completed: value })
      .then((merged) => {
        cached = merged;
        applyToUi(merged);
      })
      .catch(() => {
        /* keep in-memory value */
      });
  }
}
