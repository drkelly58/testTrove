<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    displayName: string;
    pictureUrl?: string | null;
    size?: number;
  }>(),
  {
    pictureUrl: null,
    size: 32,
  },
);

const imageFailed = ref(false);

const initials = computed(() => {
  const parts = props.displayName.trim().split(/\s+/).filter(Boolean);
  if (parts.length >= 2) {
    return (parts[0]![0]! + parts[1]![0]!).toUpperCase();
  }
  const token = parts[0] ?? '?';
  return token.slice(0, 2).toUpperCase();
});

const showImage = computed(
  () => props.pictureUrl && props.pictureUrl.trim() !== '' && !imageFailed.value,
);

function onImageError() {
  imageFailed.value = true;
}

watch(
  () => props.pictureUrl,
  () => {
    imageFailed.value = false;
  },
);
</script>

<template>
  <span
    class="user-avatar"
    :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.38)}px` }"
    aria-hidden="true"
  >
    <img
      v-if="showImage"
      class="user-avatar-img"
      :src="pictureUrl!"
      alt=""
      @error="onImageError"
    />
    <span v-else class="user-avatar-initials">{{ initials }}</span>
  </span>
</template>

<style scoped>
.user-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border-radius: 50%;
  overflow: hidden;
  border: 1px solid var(--border);
  background: color-mix(in srgb, var(--action-purple) 18%, var(--panel-2));
  color: var(--text);
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0.02em;
}

.user-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.user-avatar-initials {
  user-select: none;
}
</style>
