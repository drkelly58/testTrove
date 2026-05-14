<script setup lang="ts">
import { ref, watch } from 'vue';
import draggable from 'vuedraggable';
import IconButton from '@/components/IconButton.vue';
import { updateCase, type Section, type StepVariant, type TestCase, type TestStep } from '@/api';
import { stepsAsArray } from '@/stepsModel';

type EditableStep = TestStep & { _key: string };

const props = defineProps<{
  modelValue: boolean;
  testCase: TestCase | null;
  suiteId: number;
  sections: Section[];
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
  (e: 'saved'): void;
}>();

const title = ref('');
const precondition = ref('');
const priority = ref('medium');
const status = ref('draft');
const sectionId = ref<number | null>(null);
const stepsEdit = ref<EditableStep[]>([]);
const saving = ref(false);
const localError = ref<string | null>(null);

function newKey(): string {
  try {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }
  } catch {
    /* non-secure contexts */
  }
  return `k-${Date.now()}-${Math.random().toString(36).slice(2, 11)}`;
}

function cloneStepsFromCase(tc: TestCase): EditableStep[] {
  const list = stepsAsArray(tc.steps);
  return list.map((s) => ({
    action: s.action ?? '',
    expected: s.expected ?? '',
    variants: (s.variants ?? []).map((v) => ({ label: v.label ?? '', criteria: v.criteria ?? '' })),
    _key: newKey(),
  }));
}

watch(
  () => {
    if (!props.modelValue || !props.testCase) {
      return null;
    }
    const tc = props.testCase;
    return {
      id: tc.id,
      stepsSig: JSON.stringify(stepsAsArray(tc.steps)),
    };
  },
  (key) => {
    if (!key || !props.testCase) {
      return;
    }
    const tc = props.testCase;
    title.value = tc.title;
    precondition.value = tc.precondition ?? '';
    priority.value = tc.priority;
    status.value = tc.status;
    sectionId.value = tc.section_id;
    stepsEdit.value = cloneStepsFromCase(tc);
    localError.value = null;
  },
  { immediate: true },
);

function close() {
  emit('update:modelValue', false);
}

function addStep() {
  stepsEdit.value.push({
    action: '',
    expected: '',
    variants: [],
    _key: newKey(),
  });
}

function removeStep(index: number) {
  stepsEdit.value.splice(index, 1);
}

function duplicateStep(index: number) {
  const s = stepsEdit.value[index];
  if (!s) {
    return;
  }
  const copy: EditableStep = {
    action: s.action,
    expected: s.expected,
    variants: (s.variants ?? []).map((v) => ({ ...v })),
    _key: newKey(),
  };
  stepsEdit.value.splice(index + 1, 0, copy);
}

function addVariant(stepIndex: number) {
  const s = stepsEdit.value[stepIndex];
  if (!s) {
    return;
  }
  if (!s.variants) {
    s.variants = [];
  }
  s.variants.push({ label: '', criteria: '' });
}

function removeVariant(stepIndex: number, vIndex: number) {
  const s = stepsEdit.value[stepIndex];
  s?.variants?.splice(vIndex, 1);
}

function toPayloadSteps(): TestStep[] {
  return stepsEdit.value.map((row) => {
    const { _key: _k, variants: rawVars, ...rest } = row;
    const step: TestStep = {
      action: rest.action.trim(),
      expected: rest.expected.trim(),
    };
    const vars = (rawVars ?? [])
      .map((v) => ({
        label: (v.label ?? '').trim(),
        criteria: (v.criteria ?? '').trim(),
      }))
      .filter((v) => v.criteria !== '');
    const withLabel: StepVariant[] = vars.map((v) =>
      v.label !== '' ? { label: v.label, criteria: v.criteria } : { criteria: v.criteria },
    );
    if (withLabel.length > 0) {
      step.variants = withLabel;
    }
    return step;
  });
}

async function save() {
  if (!props.testCase) {
    return;
  }
  localError.value = null;
  const t = title.value.trim();
  if (!t) {
    localError.value = 'Title is required.';
    return;
  }
  const payloadSteps = toPayloadSteps();
  const stepsForApi = payloadSteps.filter((s) => s.action !== '' || s.expected !== '');

  saving.value = true;
  try {
    await updateCase(props.suiteId, props.testCase.id, {
      title: t,
      section_id: sectionId.value ?? props.testCase.section_id,
      precondition: precondition.value.trim() || null,
      steps: stepsForApi,
      priority: priority.value,
      status: status.value,
    });
    emit('saved');
    close();
  } catch (e) {
    localError.value = e instanceof Error ? e.message : 'Save failed';
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue && testCase" class="backdrop" @click.self="close">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="case-editor-title">
        <header class="modal-head">
          <h2 id="case-editor-title">Edit test case</h2>
          <IconButton label="Close" title="Close" @click="close">
            <svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12" /></svg>
          </IconButton>
        </header>

        <div class="modal-body">
          <div v-if="localError" class="err">{{ localError }}</div>

          <label class="field">
            <span class="lab">Title</span>
            <input v-model="title" class="input" type="text" />
          </label>

          <label class="field">
            <span class="lab">Precondition</span>
            <textarea v-model="precondition" class="textarea" rows="2" />
          </label>

          <label class="field">
            <span class="lab">Section</span>
            <select v-model.number="sectionId" class="input">
              <option v-for="section in sections" :key="section.id" :value="section.id">
                {{ section.name }}
              </option>
            </select>
          </label>

          <div class="row2">
            <label class="field">
              <span class="lab">Priority</span>
              <select v-model="priority" class="input">
                <option value="low">low</option>
                <option value="medium">medium</option>
                <option value="high">high</option>
                <option value="critical">critical</option>
              </select>
            </label>
            <label class="field">
              <span class="lab">Status</span>
              <select v-model="status" class="input">
                <option value="draft">draft</option>
                <option value="ready">ready</option>
                <option value="deprecated">deprecated</option>
              </select>
            </label>
          </div>

          <div class="steps-head">
            <span class="lab">Steps</span>
            <span class="sub">Drag to reorder · duplicate for similar flows · variants add extra criteria.</span>
          </div>

          <draggable
            :key="testCase.id"
            v-model="stepsEdit"
            item-key="_key"
            handle=".step-drag"
            class="step-list"
            :animation="180"
          >
            <template #item="{ element: step, index: si }">
              <div class="step-card">
                <div class="step-top">
                  <button type="button" class="step-drag" title="Drag to reorder" aria-label="Reorder">⠿</button>
                  <div class="step-actions">
                    <IconButton label="Duplicate step" title="Duplicate step" @click="duplicateStep(si)">
                      <svg viewBox="0 0 24 24">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2" fill="none" />
                        <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" fill="none" />
                      </svg>
                    </IconButton>
                    <IconButton danger label="Remove step" title="Remove step" @click="removeStep(si)">
                      <svg viewBox="0 0 24 24">
                        <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2" fill="none" />
                        <path d="M10 11v6M14 11v6" fill="none" />
                      </svg>
                    </IconButton>
                  </div>
                </div>
                <label class="field tight">
                  <span class="lab sm">Action</span>
                  <textarea v-model="step.action" class="textarea" rows="2" placeholder="What to do" />
                </label>
                <label class="field tight">
                  <span class="lab sm">Expected</span>
                  <textarea v-model="step.expected" class="textarea" rows="2" placeholder="What should happen" />
                </label>

                <div class="variants-block">
                  <div class="variants-head">
                    <span class="lab sm">Variants</span>
                    <IconButton accent label="Add variant" title="Add variant criteria" @click="addVariant(si)">
                      <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>
                    </IconButton>
                  </div>
                  <p class="variant-hint">Optional extra criteria (e.g. “Mobile Safari”, “Admin user”).</p>
                  <div v-for="(v, vi) in step.variants ?? []" :key="vi" class="variant-row">
                    <input v-model="v.label" class="input sm" type="text" placeholder="Label (optional)" />
                    <input v-model="v.criteria" class="input grow" type="text" placeholder="Additional criteria" />
                    <IconButton danger label="Remove variant" title="Remove variant" @click="removeVariant(si, vi)">
                      <svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12" /></svg>
                    </IconButton>
                  </div>
                </div>
              </div>
            </template>
          </draggable>

          <div class="add-step-wrap">
            <IconButton accent label="Add step" title="Add step" @click="addStep">
              <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>
            </IconButton>
          </div>
        </div>

        <footer class="modal-foot">
          <button type="button" class="btn" @click="close">Cancel</button>
          <button type="button" class="btn primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 1.5rem;
  overflow-y: auto;
}

.modal {
  width: min(640px, 100%);
  margin-top: 2rem;
  margin-bottom: 2rem;
  background: var(--panel, #111c26);
  border: 1px solid var(--border, #243548);
  border-radius: 12px;
  color: var(--text, #f1f5f9);
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

.modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid var(--border, #243548);
}

.modal-head h2 {
  margin: 0;
  font-size: 1.05rem;
}

.modal-body {
  padding: 1rem;
  max-height: calc(100vh - 12rem);
  overflow-y: auto;
}

.modal-foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.85rem 1rem;
  border-top: 1px solid var(--border, #243548);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 0.75rem;
}

.field.tight {
  margin-bottom: 0.5rem;
}

.lab {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted, #64748b);
}

.lab.sm {
  font-size: 0.72rem;
}

.row2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}

.input,
.textarea,
select.input {
  border-radius: 8px;
  border: 1px solid var(--border, #243548);
  background: var(--panel-2, #152535);
  color: inherit;
  padding: 0.45rem 0.55rem;
  font: inherit;
}

.textarea {
  resize: vertical;
  min-height: 2.5rem;
}

.input.sm {
  max-width: 140px;
}

.input.grow {
  flex: 1;
  min-width: 0;
}

.err {
  color: var(--danger, #f87171);
  font-size: 0.88rem;
  margin-bottom: 0.75rem;
}

.steps-head {
  margin: 0.5rem 0 0.35rem;
}

.steps-head .sub {
  display: block;
  font-size: 0.75rem;
  color: var(--muted, #64748b);
  margin-top: 0.2rem;
}

.step-list {
  list-style: none;
  margin: 0 0 0.75rem;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.step-card {
  border: 1px solid var(--border, #243548);
  border-radius: 10px;
  padding: 0.6rem 0.65rem;
  background: color-mix(in srgb, var(--panel-2, #152535) 92%, transparent);
}

.step-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.35rem;
}

.step-drag {
  cursor: grab;
  border: 1px solid var(--border, #243548);
  background: var(--panel, #111c26);
  color: var(--muted, #64748b);
  border-radius: 6px;
  padding: 0.2rem 0.45rem;
  font-size: 1rem;
  line-height: 1;
}

.step-drag:active {
  cursor: grabbing;
}

.step-actions {
  display: flex;
  gap: 0.2rem;
  align-items: center;
}

.add-step-wrap {
  display: flex;
  justify-content: flex-start;
  margin-top: 0.15rem;
}

.btn {
  border-radius: 8px;
  border: 1px solid var(--border, #243548);
  background: var(--panel-2, #152535);
  color: inherit;
  padding: 0.4rem 0.65rem;
  font: inherit;
  font-weight: 600;
  font-size: 0.82rem;
  cursor: pointer;
}

.btn.primary {
  border-color: color-mix(in srgb, var(--accent, #7b61ff) 55%, var(--border, #243548));
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--accent, #7b61ff) 35%, var(--panel-2, #152535)),
    var(--panel-2, #152535)
  );
}

.btn.danger {
  border-color: color-mix(in srgb, var(--danger, #f87171) 40%, var(--border, #243548));
  color: var(--danger, #f87171);
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.variants-block {
  margin-top: 0.5rem;
  padding-top: 0.5rem;
  border-top: 1px dashed var(--border, #243548);
}

.variants-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.25rem;
}

.variant-hint {
  margin: 0 0 0.4rem;
  font-size: 0.72rem;
  color: var(--muted, #64748b);
}

.variant-row {
  display: flex;
  gap: 0.35rem;
  align-items: center;
  margin-bottom: 0.35rem;
}
</style>
