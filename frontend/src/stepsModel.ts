import type { TestStep } from '@/api';

/** Normalize API `steps` (array, legacy single object, or double-encoded JSON string). */
export function stepsAsArray(raw: unknown): TestStep[] {
  if (raw == null) {
    return [];
  }
  if (typeof raw === 'string') {
    const t = raw.trim();
    if (t === '') {
      return [];
    }
    try {
      return stepsAsArray(JSON.parse(t) as unknown);
    } catch {
      return [];
    }
  }
  if (Array.isArray(raw)) {
    return raw as TestStep[];
  }
  if (typeof raw === 'object' && ('action' in raw || 'expected' in raw)) {
    return [raw as TestStep];
  }
  return [];
}
