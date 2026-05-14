<script setup lang="ts">
import { nextTick, ref, useId, watch } from 'vue';

export type FieldKind = 'text' | 'textarea' | 'number' | 'select' | 'checkbox';
export type SelectOption = { value: string | number; label: string };
export type FieldDef = {
  key: string;
  label: string;
  kind: FieldKind;
  /** Initial value when the dialog opens. */
  initial?: string | number | boolean | null;
  /** Placeholder for text/textarea/number. */
  placeholder?: string;
  /** Help text rendered under the input. */
  help?: string;
  /** For kind 'select'. */
  options?: SelectOption[];
  /** Validation: non-empty after trim (text/textarea). */
  required?: boolean;
  /** Optional min/max for number. */
  min?: number;
  max?: number;
  /** Optional rows for textarea. */
  rows?: number;
  /** Autofocus the first field with this true. If none set, the first field gets autofocus. */
  autofocus?: boolean;
};

const props = withDefaults(
  defineProps<{
    modelValue: boolean;
    /** Dialog heading, e.g. "Edit project" or "New section". */
    title: string;
    /** Field schema. */
    fields: FieldDef[];
    /** Submit button label, e.g. "Save" / "Create". Default "Save". */
    submitLabel?: string;
    cancelLabel?: string;
    /** When true, both buttons are disabled (e.g. while a parent request is pending). */
    busy?: boolean;
    /** External error to display under the form. */
    errorMessage?: string | null;
  }>(),
  {
    submitLabel: 'Save',
    cancelLabel: 'Cancel',
    busy: false,
    errorMessage: null,
  },
);

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
  (e: 'submit', values: Record<string, string | number | boolean | null>): void;
  (e: 'cancel'): void;
}>();

/** Internal editing buffer: strings in UI for text-like fields, boolean for checkbox, number|null for number. */
const values = ref<Record<string, string | number | boolean | null>>({});
const fieldErrors = ref<Record<string, string>>({});
const inputRefs = ref<Record<string, HTMLElement | null>>({});

const headingId = `entity-form-title-${useId()}`;

function setFieldRef(key: string, el: unknown) {
  inputRefs.value[key] = (el as HTMLElement | null) ?? null;
}

function initValuesFromFields(): void {
  const next: Record<string, string | number | boolean | null> = {};
  for (const f of props.fields) {
    if (f.kind === 'checkbox') {
      next[f.key] = Boolean(f.initial);
    } else if (f.kind === 'number') {
      const raw = f.initial;
      if (raw === null || raw === undefined || raw === '') {
        next[f.key] = null;
      } else {
        const n = typeof raw === 'number' ? raw : Number(raw);
        next[f.key] = Number.isFinite(n) ? n : null;
      }
    } else if (f.kind === 'select') {
      const first = f.options?.[0]?.value;
      const init = f.initial !== undefined && f.initial !== null ? f.initial : first;
      next[f.key] = init !== undefined && init !== null ? init : '';
    } else {
      next[f.key] = f.initial === null || f.initial === undefined ? '' : String(f.initial);
    }
  }
  values.value = next;
}

function focusFirstField() {
  const list = props.fields;
  if (!list.length) {
    return;
  }
  const withAuto = list.find((f) => f.autofocus);
  const targetKey = (withAuto ?? list[0])?.key;
  if (!targetKey) {
    return;
  }
  const el = inputRefs.value[targetKey];
  if (el && typeof el.focus === 'function') {
    el.focus();
  }
}

watch(
  () => [props.modelValue, props.fields] as const,
  async ([open]) => {
    fieldErrors.value = {};
    if (!open) {
      return;
    }
    initValuesFromFields();
    await nextTick();
    await nextTick();
    focusFirstField();
  },
  { immediate: true },
);

function fieldId(key: string): string {
  return `${headingId}-${key}`;
}

function cancel() {
  emit('cancel');
  emit('update:modelValue', false);
}

function onNumberInput(key: string, ev: Event) {
  const raw = (ev.target as HTMLInputElement).value;
  if (raw === '' || raw === null) {
    values.value[key] = null;
  } else {
    const n = Number(raw);
    values.value[key] = Number.isFinite(n) ? n : null;
  }
}

/**
 * Coerce submitted payload: trimmed empty optional text/textarea emit as `null` so callers can PATCH
 * "clear field" uniformly; required fields never emit blank after validation.
 */
function onSubmit() {
  fieldErrors.value = {};
  const out: Record<string, string | number | boolean | null> = {};
  let ok = true;

  for (const f of props.fields) {
    if (f.kind === 'checkbox') {
      const b = Boolean(values.value[f.key]);
      if (f.required && !b) {
        fieldErrors.value[f.key] = 'This field is required.';
        ok = false;
        continue;
      }
      out[f.key] = b;
      continue;
    }

    if (f.kind === 'select') {
      const v = values.value[f.key];
      const opt = f.options?.find((o) => o.value === v || String(o.value) === String(v));
      out[f.key] = opt ? opt.value : (v as string | number | null);
      continue;
    }

    if (f.kind === 'number') {
      const raw = values.value[f.key];
      if (raw === null || raw === '' || raw === undefined) {
        if (f.required) {
          fieldErrors.value[f.key] = 'This field is required.';
          ok = false;
        } else {
          out[f.key] = null;
        }
        continue;
      }
      const n = typeof raw === 'number' ? raw : Number(raw);
      if (!Number.isFinite(n)) {
        if (f.required) {
          fieldErrors.value[f.key] = 'Enter a valid number.';
          ok = false;
        } else {
          out[f.key] = null;
        }
        continue;
      }
      if (f.min !== undefined && n < f.min) {
        fieldErrors.value[f.key] = `Must be at least ${f.min}.`;
        ok = false;
        continue;
      }
      if (f.max !== undefined && n > f.max) {
        fieldErrors.value[f.key] = `Must be at most ${f.max}.`;
        ok = false;
        continue;
      }
      out[f.key] = n;
      continue;
    }

    const rawStr = values.value[f.key];
    const str = typeof rawStr === 'string' ? rawStr.trim() : String(rawStr ?? '').trim();
    if (f.required && str === '') {
      fieldErrors.value[f.key] = 'This field is required.';
      ok = false;
      continue;
    }
    if (str === '') {
      out[f.key] = null;
    } else {
      out[f.key] = str;
    }
  }

  if (!ok) {
    return;
  }
  emit('submit', out);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="entity-backdrop" role="presentation" @click.self="cancel">
      <section
        class="entity-dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="headingId"
        @keydown.escape.prevent="cancel"
      >
        <header class="entity-head">
          <h2 :id="headingId">{{ title }}</h2>
        </header>

        <form class="entity-body" @submit.prevent="onSubmit">
          <p v-if="errorMessage" class="form-err">{{ errorMessage }}</p>

          <div v-for="f in fields" :key="f.key" class="field-block">
            <label v-if="f.kind !== 'checkbox'" class="field" :for="fieldId(f.key)">
              <span class="lab">{{ f.label }}</span>
              <input
                v-if="f.kind === 'text'"
                :id="fieldId(f.key)"
                :ref="(el) => setFieldRef(f.key, el)"
                v-model="values[f.key]"
                class="input"
                type="text"
                :placeholder="f.placeholder"
                :disabled="busy"
                :aria-invalid="fieldErrors[f.key] ? 'true' : undefined"
                :aria-describedby="f.help ? fieldId(f.key) + '-help' : undefined"
              />
              <textarea
                v-else-if="f.kind === 'textarea'"
                :id="fieldId(f.key)"
                :ref="(el) => setFieldRef(f.key, el)"
                v-model="values[f.key]"
                class="textarea"
                :rows="f.rows ?? 3"
                :placeholder="f.placeholder"
                :disabled="busy"
                :aria-invalid="fieldErrors[f.key] ? 'true' : undefined"
                :aria-describedby="f.help ? fieldId(f.key) + '-help' : undefined"
              />
              <input
                v-else-if="f.kind === 'number'"
                :id="fieldId(f.key)"
                :ref="(el) => setFieldRef(f.key, el)"
                class="input"
                type="number"
                :placeholder="f.placeholder"
                :min="f.min"
                :max="f.max"
                :disabled="busy"
                :value="values[f.key] === null || values[f.key] === undefined ? '' : values[f.key]"
                :aria-invalid="fieldErrors[f.key] ? 'true' : undefined"
                :aria-describedby="f.help ? fieldId(f.key) + '-help' : undefined"
                @input="onNumberInput(f.key, $event)"
              />
              <select
                v-else-if="f.kind === 'select'"
                :id="fieldId(f.key)"
                :ref="(el) => setFieldRef(f.key, el)"
                v-model="values[f.key]"
                class="input"
                :disabled="busy"
                :aria-invalid="fieldErrors[f.key] ? 'true' : undefined"
                :aria-describedby="f.help ? fieldId(f.key) + '-help' : undefined"
              >
                <option v-for="opt in f.options ?? []" :key="String(opt.value)" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
              <p v-if="f.help" :id="fieldId(f.key) + '-help'" class="field-help">{{ f.help }}</p>
              <p v-if="fieldErrors[f.key]" class="field-err">{{ fieldErrors[f.key] }}</p>
            </label>

            <div v-else class="field field-checkbox">
              <label class="check-row" :for="fieldId(f.key)">
                <input
                  :id="fieldId(f.key)"
                  :ref="(el) => setFieldRef(f.key, el)"
                  v-model="values[f.key]"
                  type="checkbox"
                  :disabled="busy"
                  :aria-invalid="fieldErrors[f.key] ? 'true' : undefined"
                />
                <span class="lab">{{ f.label }}</span>
              </label>
              <p v-if="f.help" :id="fieldId(f.key) + '-help'" class="field-help">{{ f.help }}</p>
              <p v-if="fieldErrors[f.key]" class="field-err">{{ fieldErrors[f.key] }}</p>
            </div>
          </div>

          <footer class="entity-foot">
            <button type="button" class="btn" :disabled="busy" @click="cancel">
              {{ cancelLabel }}
            </button>
            <button type="submit" class="btn primary" :disabled="busy">
              {{ submitLabel }}
            </button>
          </footer>
        </form>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.entity-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
}

.entity-dialog {
  width: min(440px, 100%);
  background: var(--panel, #111c26);
  border: 1px solid var(--border, #243548);
  border-radius: 12px;
  color: var(--text, #f1f5f9);
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
  display: flex;
  flex-direction: column;
}

.entity-head {
  padding: 0.85rem 1rem;
  border-bottom: 1px solid var(--border, #243548);
}

.entity-head h2 {
  margin: 0;
  font-size: 1rem;
}

.entity-body {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.entity-body > .field-block:first-of-type {
  padding-top: 1rem;
}

.field-block {
  padding: 0 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 0.85rem;
}

.field-checkbox {
  margin-bottom: 0.85rem;
}

.check-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  font: inherit;
  color: inherit;
}

.lab {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted, #94a3b8);
}

.input,
.textarea {
  border-radius: 10px;
  border: 1px solid var(--border, #243548);
  background: var(--panel-2, #152535);
  color: var(--text, #f1f5f9);
  padding: 0.55rem 0.7rem;
  font: inherit;
}

.textarea {
  resize: vertical;
  min-height: 4rem;
}

.field-help {
  margin: 0;
  font-size: 0.78rem;
  color: var(--muted, #94a3b8);
}

.field-err {
  margin: 0;
  font-size: 0.78rem;
  color: var(--danger, #f87171);
}

.form-err {
  margin: 0 1rem 0.75rem;
  padding: 0.5rem 0.65rem;
  border-radius: 8px;
  border: 1px solid color-mix(in srgb, var(--danger, #f87171) 45%, var(--border, #243548));
  background: color-mix(in srgb, var(--danger, #f87171) 12%, var(--panel-2, #152535));
  color: var(--danger, #f87171);
  font-size: 0.85rem;
}

.entity-foot {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  margin-top: 0.25rem;
  border-top: 1px solid var(--border, #243548);
  background: color-mix(in srgb, var(--panel-2, #152535) 70%, transparent);
  border-radius: 0 0 12px 12px;
}

.entity-foot .btn:first-child {
  margin-right: auto;
}

.btn {
  border-radius: 8px;
  border: 1px solid var(--border, #243548);
  background: var(--panel-2, #152535);
  color: var(--text, #f1f5f9);
  padding: 0.5rem 0.85rem;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn.primary {
  border-color: color-mix(in srgb, var(--accent, #38bdf8) 55%, var(--border, #243548));
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--accent, #38bdf8) 35%, var(--panel-2, #152535)),
    var(--panel-2, #152535)
  );
}

.btn.primary:hover:not(:disabled) {
  filter: brightness(1.05);
}
</style>
