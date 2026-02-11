<template>
  <div class="layout">
    <header class="topbar">
      <div class="topbar-left">
        <Button icon="pi pi-arrow-left" text @click="router.push('/chargebacks')" />
        <AppLogo :clickable="true" />
      </div>
      <div class="topbar-right">
        <span class="user-name">{{ auth.user?.displayName }}</span>
        <Button label="Logout" severity="secondary" size="small" @click="handleLogout" />
      </div>
    </header>

    <main class="content">
      <ProgressSpinner v-if="loading" />

      <div v-else-if="chargeback" class="detail-card">
        <div class="detail-grid">
          <div class="detail-item">
            <span class="label">Title</span>
            <span class="value">{{ chargeback.title }}</span>
          </div>
          <div class="detail-item">
            <span class="label">Order ID</span>
            <span class="value">{{ chargeback.order_id }}</span>
          </div>
          <div class="detail-item">
            <span class="label">Template</span>
            <span class="value">{{ chargeback.template_name }}</span>
          </div>
          <div class="detail-item">
            <span class="label">Status</span>
            <Tag :value="chargeback.status" severity="info" />
          </div>
          <div class="detail-item">
            <span class="label">Created By</span>
            <span class="value">{{ chargeback.created_by_name }}</span>
          </div>
          <div class="detail-item">
            <span class="label">Created At</span>
            <span class="value">{{ formatDate(chargeback.created_at) }}</span>
          </div>
        </div>

        <div class="detail-actions">
          <Button
            v-if="chargeback.google_doc_url"
            label="Open Doc"
            icon="pi pi-external-link"
            @click="openDoc(chargeback.google_doc_url)"
          />
          <Button
            v-if="auth.user?.role === 'admin'"
            label="Remove"
            icon="pi pi-trash"
            severity="danger"
            outlined
            @click="confirmRemove"
          />
        </div>
      </div>

      <Message v-else severity="error" :closable="false">Chargeback not found.</Message>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import AppLogo from '../components/AppLogo.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()

const chargeback = ref<any>(null)
const loading = ref(true)

async function fetchChargeback() {
  loading.value = true
  try {
    const res = await fetch(`/api/chargebacks/${route.params.id}`, { credentials: 'include' })
    if (res.ok) {
      const data = await res.json()
      chargeback.value = data.chargeback
    }
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load chargeback', life: 3000 })
  } finally {
    loading.value = false
  }
}

function confirmRemove() {
  confirm.require({
    message: `Remove "${chargeback.value.title}"?`,
    header: 'Confirm',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: () => removeChargeback(),
  })
}

async function removeChargeback() {
  try {
    await fetch(`/api/chargebacks/${chargeback.value.id}`, { method: 'DELETE', credentials: 'include' })
    toast.add({ severity: 'success', summary: 'Removed', detail: 'Chargeback removed', life: 3000 })
    router.push('/chargebacks')
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to remove', life: 3000 })
  }
}

function openDoc(url: string) {
  window.open(url, '_blank')
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}

onMounted(fetchChargeback)
</script>

<style scoped>
.layout {
  min-height: 100vh;
}

.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 2rem;
  background: white;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.topbar-left {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.user-name {
  color: #6b7280;
  font-size: 0.875rem;
}

.content {
  padding: 2rem;
  max-width: 800px;
  margin: 0 auto;
}

.detail-card {
  background: white;
  padding: 2rem;
  border-radius: 12px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
}

.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.5rem;
  margin-bottom: 2rem;
}

.detail-item .label {
  display: block;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #6b7280;
  margin-bottom: 0.25rem;
}

.detail-item .value {
  font-size: 1rem;
  color: #111827;
}

.detail-actions {
  display: flex;
  gap: 0.75rem;
  padding-top: 1.5rem;
  border-top: 1px solid #e5e7eb;
}
</style>
