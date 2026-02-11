<template>
  <div class="layout">
    <header class="topbar">
      <AppLogo :clickable="true" />
      <div class="topbar-right">
        <Button icon="pi pi-cog" text @click="router.push('/settings')" v-tooltip="'Settings'" />
        <span class="user-name">{{ auth.user?.displayName }}</span>
        <Button label="Logout" severity="secondary" size="small" @click="handleLogout" />
      </div>
    </header>

    <main class="content">
      <div class="toolbar">
        <Button label="New Chargeback" icon="pi pi-plus" @click="router.push('/chargebacks/new')" />
      </div>

      <DataTable
        :value="chargebacks"
        :loading="loadingList"
        stripedRows
        responsiveLayout="scroll"
        tableStyle="white-space: nowrap"
        paginator
        :rows="rowsPerPage"
        :rowsPerPageOptions="[10, 25, 50, 100]"
        paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
        currentPageReportTemplate="Showing {first} to {last} of {totalRecords} chargebacks"
      >
        <Column field="title" header="Title" />
        <Column field="order_id" header="Order ID" />
        <Column field="template_name" header="Template" />
        <Column field="created_by_name" header="Created By" />
        <Column field="created_at" header="Created">
          <template #body="{ data }">{{ formatDate(data.created_at) }}</template>
        </Column>
        <Column field="status" header="Status">
          <template #body="{ data }">
            <Tag :value="data.status" severity="info" />
          </template>
        </Column>
        <Column header="Actions">
          <template #body="{ data }">
            <div class="actions">
              <Button icon="pi pi-eye" size="small" text @click="router.push(`/chargebacks/${data.id}`)" />
              <Button
                v-if="data.google_doc_url"
                icon="pi pi-external-link"
                size="small"
                text
                @click="openDoc(data.google_doc_url)"
              />
              <Button
                v-if="auth.user?.role === 'admin'"
                icon="pi pi-trash"
                size="small"
                text
                severity="danger"
                @click="confirmRemove(data)"
              />
            </div>
          </template>
        </Column>
        <template #empty>No chargebacks found.</template>
      </DataTable>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import AppLogo from '../components/AppLogo.vue'

const auth = useAuthStore()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()

const chargebacks = ref<any[]>([])
const loadingList = ref(false)
const rowsPerPage = ref(25)

async function fetchChargebacks() {
  loadingList.value = true
  try {
    const res = await fetch('/api/chargebacks', { credentials: 'include' })
    const data = await res.json()
    chargebacks.value = data.chargebacks
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load chargebacks', life: 3000 })
  } finally {
    loadingList.value = false
  }
}

function confirmRemove(chargeback: any) {
  confirm.require({
    message: `Remove "${chargeback.title}"?`,
    header: 'Confirm',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: () => removeChargeback(chargeback.id),
  })
}

async function removeChargeback(id: number) {
  try {
    await fetch(`/api/chargebacks/${id}`, { method: 'DELETE', credentials: 'include' })
    chargebacks.value = chargebacks.value.filter((c) => c.id !== id)
    toast.add({ severity: 'success', summary: 'Removed', detail: 'Chargeback removed', life: 3000 })
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to remove', life: 3000 })
  }
}

function openDoc(url: string) {
  window.open(url, '_blank')
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}

onMounted(() => {
  fetchChargebacks()
})
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
  max-width: 1600px;
  margin: 0 auto;
}

.toolbar {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.actions {
  display: flex;
  gap: 0.25rem;
}
</style>
