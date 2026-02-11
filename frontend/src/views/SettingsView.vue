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
      <div class="settings-card">
        <TabView v-model:activeIndex="activeTab">
          <!-- Placeholder Mappings Tab -->
          <TabPanel header="Placeholder Mappings">
            <div class="tab-content">
              <div class="section-header">
                <h2>Placeholder Mappings</h2>
                <p class="section-desc">
                  Map template placeholders to WooCommerce order fields. When you fetch an order,
                  these mappings determine which fields auto-fill. Screenshot placeholders are handled
                  automatically and don't require field mappings.
                </p>
              </div>

              <div class="mappings-table-wrapper">
                <DataTable
            :value="nonScreenshotMappings"
            :loading="loading"
            stripedRows
            responsiveLayout="scroll"
            class="mappings-table"
          >
                  <Column field="placeholder" header="Placeholder" sortable>
                    <template #body="{ data }">
                      <code class="placeholder-code">{{ data.placeholder }}</code>
                    </template>
                  </Column>
                  <Column field="woo_field" header="WooCommerce Field" sortable>
                    <template #body="{ data }">
                      <InputText
                        :modelValue="data.woo_field ?? ''"
                        @update:modelValue="(val: string) => setMappingField(data.id, val)"
                        @blur="() => commitMapping(data)"
                        class="mapping-input"
                      />
                    </template>
                  </Column>
                  <Column header="Status" style="width: 120px">
                    <template #body="{ data }">
                      <Tag v-if="data.woo_field" value="Mapped" severity="success" />
                      <Tag v-else value="Unmapped" severity="warn" />
                    </template>
                  </Column>
                  <Column header="Actions" style="width: 120px">
                    <template #body="{ data }">
                      <div class="row-actions">
                        <Button
                          label="Update"
                          size="small"
                          severity="secondary"
                          @click="handleUpdate(data)"
                        />
                        <Button
                          icon="pi pi-trash"
                          size="small"
                          text
                          severity="danger"
                          @click="confirmDelete(data)"
                        />
                      </div>
                    </template>
                  </Column>
                  <template #empty>
                    <div class="empty-state">
                      No mappings yet. Create a chargeback from a template to auto-discover placeholders.
                    </div>
                  </template>
                </DataTable>
              </div>
            </div>
          </TabPanel>

          <!-- Google Drive Tab -->
          <TabPanel header="Google Drive Connection">
            <div class="tab-content">
              <div class="section-header">
                <h2>Google Drive Connection</h2>
                <p class="section-desc">
                  Connect your Google account to access templates and create chargeback documents.
                </p>
              </div>

              <div class="connection-status">
                <div v-if="googleConnected" class="status-connected">
                  <i class="pi pi-check-circle"></i>
                  <div>
                    <strong>Connected</strong>
                    <p>Your Google account is connected and ready to use.</p>
                  </div>
                </div>
                <div v-else class="status-disconnected">
                  <i class="pi pi-exclamation-triangle"></i>
                  <div>
                    <strong>Not Connected</strong>
                    <p>Connect your Google account to use templates and create documents.</p>
                  </div>
                  <Button
                    label="Connect Google"
                    icon="pi pi-google"
                    severity="warn"
                    @click="connectGoogle"
                  />
                </div>
              </div>
            </div>
          </TabPanel>

          <!-- Users Tab -->
          <TabPanel header="Users">
            <div class="tab-content">
              <!-- Change Own Password Section -->
              <div class="section-header">
                <h2>Change My Password</h2>
                <p class="section-desc">Update your own password</p>
              </div>

              <div class="password-form">
                <div class="field">
                  <label>New Password</label>
                  <InputText v-model="newPassword" type="password" class="w-full" />
                </div>
                <div class="field">
                  <label>Confirm New Password</label>
                  <InputText v-model="confirmPassword" type="password" class="w-full" />
                </div>
                <Button
                  label="Change Password"
                  icon="pi pi-key"
                  @click="changeOwnPassword"
                  :loading="changingPassword"
                />
              </div>

              <!-- Manage Users Section (Admin Only) -->
              <div v-if="auth.user?.role === 'admin'" class="users-section">
                <div class="section-header">
                  <h2>User Management</h2>
                  <p class="section-desc">Add and manage system users</p>
                </div>

                <!-- Add User Form -->
                <div class="add-user-form">
                  <h3>Add New User</h3>
                  <div class="form-row">
                    <div class="field">
                      <label>Display Name</label>
                      <InputText v-model="newUser.displayName" class="w-full" placeholder="John Doe" />
                    </div>
                    <div class="field">
                      <label>Email</label>
                      <InputText v-model="newUser.email" type="email" class="w-full" placeholder="user@example.com" />
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="field">
                      <label>Password</label>
                      <div class="password-input-group">
                        <InputText v-model="newUser.password" type="text" class="w-full" />
                        <Button
                          icon="pi pi-refresh"
                          @click="generatePassword"
                          v-tooltip="'Generate Password'"
                          size="small"
                        />
                      </div>
                    </div>
                    <div class="field">
                      <label>Role</label>
                      <Select
                        v-model="newUser.role"
                        :options="['user', 'admin']"
                        class="w-full"
                      />
                    </div>
                  </div>
                  <Button
                    label="Add User"
                    icon="pi pi-user-plus"
                    @click="addUser"
                    :loading="addingUser"
                  />
                </div>

                <!-- Users List -->
                <div class="users-list">
                  <h3>All Users</h3>
                  <DataTable :value="users" :loading="loadingUsers" stripedRows>
                    <Column field="display_name" header="Name" sortable />
                    <Column field="email" header="Email" sortable />
                    <Column field="role" header="Role" sortable>
                      <template #body="{ data }">
                        <Tag :value="data.role" :severity="data.role === 'admin' ? 'danger' : 'info'" />
                      </template>
                    </Column>
                    <Column field="created_at" header="Created" sortable>
                      <template #body="{ data }">{{ formatDate(data.created_at) }}</template>
                    </Column>
                    <Column header="Actions" style="width: 100px">
                      <template #body="{ data }">
                        <Button
                          v-if="data.id !== auth.user?.userId"
                          icon="pi pi-trash"
                          size="small"
                          text
                          severity="danger"
                          @click="confirmDeleteUser(data)"
                        />
                      </template>
                    </Column>
                    <template #empty>No users found.</template>
                  </DataTable>
                </div>
              </div>
            </div>
          </TabPanel>
        </TabView>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import Select from 'primevue/select'
import AppLogo from '../components/AppLogo.vue'

const auth = useAuthStore()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()

const activeTab = ref(0)
const mappings = ref<any[]>([])
const loading = ref(false)
const googleConnected = ref(false)

// Users management
const users = ref<any[]>([])
const loadingUsers = ref(false)
const addingUser = ref(false)
const newUser = ref({ displayName: '', email: '', password: '', role: 'user' })

// Password change
const newPassword = ref('')
const confirmPassword = ref('')
const changingPassword = ref(false)

// Computed: filter out screenshot placeholders from mappings
const nonScreenshotMappings = computed(() => {
  return mappings.value.filter((m: any) => !m.placeholder.toLowerCase().includes('screenshot'))
})

async function checkGoogleStatus() {
  try {
    const res = await fetch('/api/oauth/google/status', { credentials: 'include' })
    const data = await res.json()
    googleConnected.value = data.connected
  } catch {
    googleConnected.value = false
  }
}

function connectGoogle() {
  window.location.href = '/api/oauth/google/start'
}

function setMappingField(id: number, value: string) {
  const idx = mappings.value.findIndex((m: any) => m.id === id)
  if (idx !== -1) {
    mappings.value[idx] = { ...mappings.value[idx], woo_field: value || null }
  }
}

function commitMapping(data: any) {
  const id = data.id
  const current = data.woo_field ?? ''
  const trimmed = (typeof current === 'string' ? current : '').trim()
  const toSave = trimmed || null
  if (String(toSave ?? '') !== String(data.woo_field ?? '')) {
    updateMapping(id, toSave)
  }
}

function handleUpdate(data: any) {
  const current = data.woo_field ?? ''
  const trimmed = (typeof current === 'string' ? current : '').trim()
  updateMapping(data.id, trimmed || null)
}

async function fetchMappings() {
  loading.value = true
  try {
    const res = await fetch('/api/settings/mappings', { credentials: 'include' })
    const data = await res.json()
    mappings.value = data.mappings
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load mappings', life: 3000 })
  } finally {
    loading.value = false
  }
}

async function updateMapping(id: number, wooField: string | null) {
  try {
    const res = await fetch(`/api/settings/mappings/${id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ woo_field: wooField || null }),
    })
    if (!res.ok) throw new Error('Update failed')
    const data = await res.json()
    const idx = mappings.value.findIndex((m: any) => m.id === id)
    if (idx !== -1) {
      mappings.value[idx] = data.mapping
    }
    toast.add({ severity: 'success', summary: 'Saved', detail: 'Mapping updated', life: 3000 })
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to update mapping', life: 3000 })
  }
}

function confirmDelete(mapping: any) {
  confirm.require({
    message: `Remove mapping for "${mapping.placeholder}"?`,
    header: 'Confirm Delete',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: () => deleteMapping(mapping.id),
  })
}

async function deleteMapping(id: number) {
  try {
    await fetch(`/api/settings/mappings/${id}`, {
      method: 'DELETE',
      credentials: 'include',
    })
    mappings.value = mappings.value.filter((m: any) => m.id !== id)
    toast.add({ severity: 'success', summary: 'Deleted', detail: 'Mapping removed', life: 3000 })
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to delete mapping', life: 3000 })
  }
}

async function fetchUsers() {
  loadingUsers.value = true
  try {
    const res = await fetch('/api/users', { credentials: 'include' })
    const data = await res.json()
    users.value = data.users
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load users', life: 3000 })
  } finally {
    loadingUsers.value = false
  }
}

function generatePassword() {
  const length = 16
  const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()'
  let password = ''
  for (let i = 0; i < length; i++) {
    password += charset.charAt(Math.floor(Math.random() * charset.length))
  }
  newUser.value.password = password
}

async function addUser() {
  if (!newUser.value.email || !newUser.value.password || !newUser.value.displayName) {
    toast.add({ severity: 'warn', summary: 'Missing fields', detail: 'All fields are required', life: 3000 })
    return
  }

  addingUser.value = true
  try {
    const res = await fetch('/api/users', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        email: newUser.value.email,
        password: newUser.value.password,
        displayName: newUser.value.displayName,
        role: newUser.value.role,
      }),
    })
    if (!res.ok) {
      const err = await res.json()
      throw new Error(err.error || 'Failed to add user')
    }
    toast.add({ severity: 'success', summary: 'Success', detail: 'User added successfully', life: 3000 })
    newUser.value = { displayName: '', email: '', password: '', role: 'user' }
    await fetchUsers()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 3000 })
  } finally {
    addingUser.value = false
  }
}

function confirmDeleteUser(user: any) {
  confirm.require({
    message: `Delete user "${user.display_name}"?`,
    header: 'Confirm Delete',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: () => deleteUser(user.id),
  })
}

async function deleteUser(id: number) {
  try {
    const res = await fetch(`/api/users/${id}`, {
      method: 'DELETE',
      credentials: 'include',
    })
    if (!res.ok) throw new Error('Failed to delete user')
    toast.add({ severity: 'success', summary: 'Deleted', detail: 'User deleted', life: 3000 })
    await fetchUsers()
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to delete user', life: 3000 })
  }
}

async function changeOwnPassword() {
  if (!newPassword.value || !confirmPassword.value) {
    toast.add({ severity: 'warn', summary: 'Missing fields', detail: 'Both password fields are required', life: 3000 })
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    toast.add({ severity: 'warn', summary: 'Mismatch', detail: 'Passwords do not match', life: 3000 })
    return
  }

  changingPassword.value = true
  try {
    const res = await fetch('/api/users/me/password', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ password: newPassword.value }),
    })
    if (!res.ok) throw new Error('Failed to change password')
    toast.add({ severity: 'success', summary: 'Success', detail: 'Password changed successfully', life: 3000 })
    newPassword.value = ''
    confirmPassword.value = ''
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to change password', life: 3000 })
  } finally {
    changingPassword.value = false
  }
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}

onMounted(async () => {
  await checkGoogleStatus()
  await fetchMappings()
  if (auth.user?.role === 'admin') {
    await fetchUsers()
  }
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
  max-width: 1200px;
  margin: 0 auto;
}

.settings-card {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
  overflow: hidden;
}

.tab-content {
  padding: 2rem;
}

.section-card {
  background: transparent;
  padding: 0;
}

.section-header {
  margin-bottom: 1.5rem;
}

.section-header h2 {
  font-size: 1.1rem;
  margin-bottom: 0.25rem;
}

.section-desc {
  font-size: 0.875rem;
  color: #6b7280;
}

.connection-status {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 1rem;
}

.status-connected {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
}

.status-connected .pi-check-circle {
  color: #16a34a;
  font-size: 1.5rem;
  margin-top: 0.25rem;
}

.status-connected strong {
  display: block;
  color: #16a34a;
  margin-bottom: 0.25rem;
}

.status-connected p {
  margin: 0;
  color: #6b7280;
  font-size: 0.875rem;
}

.status-disconnected {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
}

.status-disconnected .pi-exclamation-triangle {
  color: #f59e0b;
  font-size: 1.5rem;
  margin-top: 0.25rem;
}

.status-disconnected strong {
  display: block;
  color: #d97706;
  margin-bottom: 0.25rem;
}

.status-disconnected p {
  margin: 0 0 1rem 0;
  color: #6b7280;
  font-size: 0.875rem;
}

.mappings-table-wrapper {
  overflow-x: hidden;
  max-width: 100%;
}

.mappings-table {
  table-layout: auto;
}

/* Placeholder column (first column): allow wrapping, no horizontal scroll */
:deep(.p-datatable-tbody tr > td:first-child) {
  white-space: normal;
  word-break: break-word;
  min-width: 0;
  max-width: 320px;
}

.placeholder-code {
  display: inline-block;
  max-width: 100%;
  white-space: normal;
  word-break: break-word;
  background: #f3f4f6;
  padding: 0.15rem 0.5rem;
  border-radius: 4px;
  font-size: 0.85rem;
  font-family: 'Courier New', monospace;
}

.mapping-input {
  width: 100%;
  min-width: 0;
  max-width: 100%;
  box-sizing: border-box;
}

.row-actions {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.empty-state {
  text-align: center;
  padding: 2rem;
  color: #9ca3af;
}

/* Users Tab */
.password-form {
  background: #f9fafb;
  padding: 1.5rem;
  border-radius: 8px;
  margin-bottom: 2rem;
  max-width: 500px;
}

.password-form .field {
  margin-bottom: 1rem;
}

.password-form .field:last-of-type {
  margin-bottom: 1.5rem;
}

.users-section {
  margin-top: 3rem;
  padding-top: 2rem;
  border-top: 2px solid #e5e7eb;
}

.add-user-form {
  background: #f9fafb;
  padding: 1.5rem;
  border-radius: 8px;
  margin-bottom: 2rem;
}

.add-user-form h3 {
  font-size: 1rem;
  margin-bottom: 1rem;
  color: #374151;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin-bottom: 1rem;
}

.field {
  margin-bottom: 1rem;
}

.field label {
  display: block;
  margin-bottom: 0.4rem;
  font-weight: 500;
  font-size: 0.875rem;
  color: #374151;
}

.password-input-group {
  display: flex;
  gap: 0.5rem;
}

.password-input-group .w-full {
  flex: 1;
}

.users-list {
  margin-top: 2rem;
}

.users-list h3 {
  font-size: 1rem;
  margin-bottom: 1rem;
  color: #374151;
}

.w-full {
  width: 100%;
}

@media (max-width: 768px) {
  .form-row {
    grid-template-columns: 1fr;
  }
}
</style>
