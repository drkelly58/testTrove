import { ref } from 'vue';

/** Run overview: when true, expanding a case collapses any other open case. */
export const RUN_OVERVIEW_SINGLE_EXPAND_KEY = 'testtrove.runOverview.singleExpand' as const;

function readStoredFlag(key: string, defaultValue: boolean): boolean {
  try {
    const raw = localStorage.getItem(key);
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

function persistFlag(key: string, value: boolean) {
  try {
    localStorage.setItem(key, value ? '1' : '0');
  } catch {
    /* ignore quota / private mode */
  }
}

/** Default true: accordion-style overview (one open case). */
export const runOverviewSingleExpand = ref(
  readStoredFlag(RUN_OVERVIEW_SINGLE_EXPAND_KEY, true),
);

export function setRunOverviewSingleExpand(value: boolean) {
  runOverviewSingleExpand.value = value;
  persistFlag(RUN_OVERVIEW_SINGLE_EXPAND_KEY, value);
}
