<template>
  <div class="app-logo" :class="{ clickable }" @click="handleClick">
    <div class="logo-icon">
      <i class="pi pi-credit-card"></i>
    </div>
    <div class="logo-text">
      <div class="logo-name">PFM Chargebacks</div>
      <div class="logo-version">v{{ version }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { version } from '../../package.json'
import { useRouter } from 'vue-router'

const props = defineProps<{
  clickable?: boolean
}>()

const router = useRouter()

function handleClick() {
  if (props.clickable) {
    router.push('/chargebacks')
  }
}
</script>

<style scoped>
.app-logo {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  user-select: none;
  transition: opacity 0.2s;
}

.app-logo.clickable {
  cursor: pointer;
}

.app-logo.clickable:hover {
  opacity: 0.8;
}

.logo-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #fb923c 0%, #f97316 100%);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 1.25rem;
  box-shadow: 0 2px 8px rgba(251, 146, 60, 0.4);
}

.logo-text {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.logo-name {
  font-weight: 600;
  font-size: 1rem;
  color: #1f2937;
  line-height: 1;
}

.logo-version {
  font-size: 0.75rem;
  color: #6b7280;
  line-height: 1;
}
</style>
