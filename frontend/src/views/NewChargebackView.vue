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
      <div class="form-card">
        <!-- Step indicator -->
        <div class="steps">
          <span :class="['step-badge', { active: step === 1 }]">1. Select Template</span>
          <span class="step-arrow">&#8594;</span>
          <span :class="['step-badge', { active: step === 2 }]">2. Fill Placeholders</span>
        </div>

        <!-- STEP 1: Template Selection -->
        <div v-if="step === 1">
          <div class="info-banner">
            <i class="pi pi-info-circle"></i>
            <div class="info-content">
              <p class="info-title">Templates are loaded from Google Drive</p>
              <p class="info-text">
                To add new templates or edit existing ones, visit the 
                <a href="https://drive.google.com/drive/folders/1_7SKgBKqjPewWMCKD-fcxD9PKUdrAQ0x?ths=true" target="_blank" rel="noopener noreferrer">
                  Templates folder <i class="pi pi-external-link"></i>
                </a>
              </p>
            </div>
          </div>

          <div class="field">
            <label>Template</label>
            <Select
              v-model="selectedTemplate"
              :options="templates"
              optionLabel="name"
              placeholder="Select a template"
              class="w-full"
              :loading="loadingTemplates"
            />
          </div>
          <div class="field">
            <label>Order ID</label>
            <InputText v-model="orderId" class="w-full" />
          </div>
          <div class="field">
            <label>Title</label>
            <InputText v-model="title" class="w-full" />
          </div>
          <div class="form-actions">
            <Button label="Cancel" severity="secondary" @click="router.push('/chargebacks')" />
            <Button label="Next" icon="pi pi-arrow-right" iconPos="right" @click="goToStep2" />
          </div>
        </div>

        <!-- STEP 2: Placeholder Fields -->
        <div v-if="step === 2">
          <div class="summary">
            <div class="summary-info">
              <p><strong>Template:</strong> {{ selectedTemplate?.name }}</p>
              <p><strong>Order ID:</strong> {{ orderId }}</p>
              <p><strong>Title:</strong> {{ title || titlePlaceholder }}</p>
            </div>
            <Button
              label="Fetch Order"
              icon="pi pi-download"
              size="small"
              :loading="fetchingOrder"
              @click="fetchOrder"
            />
          </div>

          <ProgressSpinner v-if="loadingPlaceholders" />

          <div v-else-if="textPlaceholders.length > 0 || screenshotPlaceholders.length > 0">
            <!-- Text Placeholders -->
            <div v-if="textPlaceholders.length > 0" class="placeholders-form">
              <div class="field" v-for="key in textPlaceholders" :key="key">
                <label>
                  {{ key }}
                  <i v-if="autoFilledFields.has(key)" class="pi pi-check-circle auto-filled-icon"></i>
                </label>
                <InputText
                  v-model="placeholderValues[key]"
                  :class="{ 'field-filled': placeholderValues[key] }"
                  class="w-full"
                />
              </div>
            </div>

            <!-- Screenshot Placeholders -->
            <div v-if="screenshotPlaceholders.length > 0" class="screenshots-section">
              <h3 class="screenshots-title">Screenshots</h3>
              <p class="screenshots-hint">
                Upload images by dragging & dropping, clicking Browse, or pressing Ctrl+V after clicking in the box.
                <br />
                <small>Tip: In templates, use <code>[screenshot_1 Description]</code> to add helpful labels</small>
              </p>
              <div class="screenshot-field" v-for="key in screenshotPlaceholders" :key="key">
                <label>
                  <span class="screenshot-label">{{ formatScreenshotLabel(key) }}</span>
                </label>
                <div
                  class="screenshot-upload-area"
                  :class="{ 'has-file': screenshots[key] }"
                  @drop.prevent="handleDrop($event, key)"
                  @dragover.prevent
                  @paste="handlePaste($event, key)"
                  @click="focusForPaste($event, key)"
                  tabindex="0"
                >
                  <div v-if="!screenshots[key]" class="upload-prompt">
                    <i class="pi pi-cloud-upload"></i>
                    <p>Click here to focus, then press Ctrl+V to paste</p>
                    <p class="or-text">or</p>
                    <Button
                      label="Browse Files"
                      icon="pi pi-folder-open"
                      size="small"
                      @click.stop="triggerFileInput(key)"
                      class="browse-btn"
                    />
                    <input
                      type="file"
                      accept="image/*"
                      @change="handleFileSelect($event, key)"
                      :ref="(el) => fileInputRefs[key] = el"
                      class="file-input-hidden"
                    />
                  </div>
                  <div v-else class="uploaded-preview">
                    <img :src="getPreviewUrl(key)" alt="Screenshot preview" />
                    <Button
                      icon="pi pi-times"
                      severity="danger"
                      size="small"
                      @click="removeScreenshot(key)"
                      class="remove-btn"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-else-if="!loadingPlaceholders" class="no-placeholders">
            <p>No placeholders found in this template. The document will be created as-is.</p>
          </div>

          <div class="form-actions">
            <Button label="Back" severity="secondary" icon="pi pi-arrow-left" @click="step = 1" />
            <Button label="Create" icon="pi pi-check" :loading="creating" @click="createChargeback" />
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import ProgressSpinner from 'primevue/progressspinner'
import FileUpload from 'primevue/fileupload'
import Image from 'primevue/image'
import AppLogo from '../components/AppLogo.vue'

const auth = useAuthStore()
const router = useRouter()
const toast = useToast()

const step = ref(1)

// Step 1
const templates = ref<any[]>([])
const loadingTemplates = ref(false)
const selectedTemplate = ref<any>(null)
const orderId = ref('')
const title = ref('')

// Step 2
const placeholderKeys = ref<string[]>([])
const placeholderValues = ref<Record<string, string>>({})
const screenshots = ref<Record<string, File | null>>({})
const fileInputRefs = ref<Record<string, any>>({})
const loadingPlaceholders = ref(false)
const creating = ref(false)
const fetchingOrder = ref(false)
const autoFilledFields = reactive(new Set<string>())

const titlePlaceholder = computed(() => `Chargeback #${orderId.value || ''}`)

// Computed: separate text placeholders from screenshot placeholders
const textPlaceholders = computed(() => {
  return placeholderKeys.value.filter((key) => !key.toLowerCase().includes('screenshot'))
})

const screenshotPlaceholders = computed(() => {
  return placeholderKeys.value.filter((key) => key.toLowerCase().includes('screenshot'))
})

async function fetchTemplates() {
  loadingTemplates.value = true
  try {
    const res = await fetch('/api/templates', { credentials: 'include' })
    const data = await res.json()
    templates.value = data.templates
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load templates', life: 3000 })
  } finally {
    loadingTemplates.value = false
  }
}

async function goToStep2() {
  if (!selectedTemplate.value || !orderId.value) {
    toast.add({ severity: 'warn', summary: 'Missing fields', detail: 'Template and Order ID are required', life: 3000 })
    return
  }

  step.value = 2
  loadingPlaceholders.value = true

  try {
    const res = await fetch(`/api/templates/${selectedTemplate.value.id}/placeholders`, {
      credentials: 'include',
    })
    if (!res.ok) throw new Error('Failed to load placeholders')
    const data = await res.json()
    placeholderKeys.value = data.placeholders

    const initial: Record<string, string> = {}
    const initialScreenshots: Record<string, File | null> = {}
    for (const key of data.placeholders) {
      if (key.toLowerCase().includes('screenshot')) {
        initialScreenshots[key] = null
      } else {
        initial[key] = ''
      }
    }
    placeholderValues.value = initial
    screenshots.value = initialScreenshots
    autoFilledFields.clear()

    // Sync placeholders to DB mappings
    try {
      await fetch('/api/settings/mappings/sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ placeholders: data.placeholders }),
      })
    } catch {
      // non-critical, continue
    }
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 5000 })
    step.value = 1
  } finally {
    loadingPlaceholders.value = false
  }
}

async function fetchOrder() {
  if (!orderId.value) return
  fetchingOrder.value = true
  const placeholders = placeholderKeys.value
  const url = `/api/woocommerce/order/${orderId.value}/mapped`
  try {
    console.log('[fetchOrder] request', { url, orderId: orderId.value, placeholdersCount: placeholders.length })
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ placeholders }),
    })
    if (!res.ok) {
      throw new Error('Order not found')
    }
    const { order, mapped } = await res.json()
    console.log('[fetchOrder] response', { order, mapped })

    // Auto-fill placeholders using DB mappings (match case-insensitive so DB "[order number]" matches form "[Order Number]")
    for (const [placeholder, value] of Object.entries(mapped as Record<string, string>)) {
      if (!value) continue
      const formKey = Object.keys(placeholderValues.value).find(
        (k) => k.toLowerCase() === placeholder.toLowerCase()
      )
      if (formKey) {
        placeholderValues.value[formKey] = value
        autoFilledFields.add(formKey)
      }
    }

    const filled = autoFilledFields.size
    toast.add({
      severity: filled > 0 ? 'success' : 'info',
      summary: 'Order fetched',
      detail: `${filled} field(s) auto-filled from order #${orderId.value}`,
      life: 3000,
    })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 5000 })
  } finally {
    fetchingOrder.value = false
  }
}

// Screenshot handling functions
function triggerFileInput(key: string) {
  const input = fileInputRefs.value[key]
  if (input) {
    input.click()
  }
}

function focusForPaste(event: Event, key: string) {
  // Don't do anything special, just let the div receive focus naturally
  // The paste event will work once the div is focused
}

function handleFileSelect(event: Event, key: string) {
  const input = event.target as HTMLInputElement
  if (input.files && input.files[0]) {
    const file = input.files[0]
    if (file.type.startsWith('image/')) {
      screenshots.value[key] = file
    } else {
      toast.add({ severity: 'warn', summary: 'Invalid file', detail: 'Please select an image file', life: 3000 })
    }
  }
}

function handleDrop(event: DragEvent, key: string) {
  const files = event.dataTransfer?.files
  if (files && files[0]) {
    const file = files[0]
    if (file.type.startsWith('image/')) {
      screenshots.value[key] = file
    } else {
      toast.add({ severity: 'warn', summary: 'Invalid file', detail: 'Please drop an image file', life: 3000 })
    }
  }
}

function handlePaste(event: ClipboardEvent, key: string) {
  const items = event.clipboardData?.items
  if (!items) return

  for (let i = 0; i < items.length; i++) {
    if (items[i].type.startsWith('image/')) {
      const file = items[i].getAsFile()
      if (file) {
        screenshots.value[key] = file
        toast.add({ severity: 'success', summary: 'Pasted', detail: 'Screenshot pasted successfully', life: 2000 })
        return
      }
    }
  }
}

function removeScreenshot(key: string) {
  screenshots.value[key] = null
}

function getPreviewUrl(key: string): string {
  const file = screenshots.value[key]
  if (file) {
    return URL.createObjectURL(file)
  }
  return ''
}

function formatScreenshotLabel(placeholder: string): string {
  // Remove the brackets and parse the content
  const content = placeholder.slice(1, -1) // Remove [ and ]
  
  // Try to split into screenshot identifier and description
  // Pattern: "screenshot_1 Description here" or "screenshot_1"
  const match = content.match(/^(screenshot[_\s]*\d+)\s*(.*)$/i)
  
  if (match) {
    const identifier = match[1].trim()
    const description = match[2].trim()
    
    if (description) {
      // Format: "Screenshot 1: Description here"
      const num = identifier.match(/\d+/)?.[0] || ''
      return `Screenshot ${num}: ${description}`
    } else {
      // Format: "Screenshot 1"
      const num = identifier.match(/\d+/)?.[0] || ''
      return `Screenshot ${num}`
    }
  }
  
  // Fallback: return the full placeholder
  return placeholder
}

async function createChargeback() {
  creating.value = true
  try {
    const formData = new FormData()
    formData.append('templateId', selectedTemplate.value.id.toString())
    formData.append('templateName', selectedTemplate.value.name)
    formData.append('orderId', orderId.value)
    if (title.value) {
      formData.append('title', title.value)
    }
    formData.append('placeholders', JSON.stringify(placeholderValues.value))

    // Add screenshots
    for (const [key, file] of Object.entries(screenshots.value)) {
      if (file) {
        formData.append(`screenshot:${key}`, file)
      }
    }

    const res = await fetch('/api/chargebacks', {
      method: 'POST',
      credentials: 'include',
      body: formData,
    })
    if (!res.ok) {
      const err = await res.json()
      throw new Error(err.error || 'Creation failed')
    }
    const data = await res.json()
    toast.add({ severity: 'success', summary: 'Created', detail: 'Chargeback created successfully', life: 3000 })
    router.push(`/chargebacks/${data.chargeback.id}`)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 5000 })
  } finally {
    creating.value = false
  }
}

function placeholderLabel(key: string): string {
  const inner = key.slice(1, -1)
  return inner.charAt(0).toUpperCase() + inner.slice(1)
}

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}

onMounted(fetchTemplates)
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
  max-width: 700px;
  margin: 0 auto;
}

.form-card {
  background: white;
  padding: 2rem;
  border-radius: 12px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
}

.steps {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 2rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid #e5e7eb;
}

.step-badge {
  font-size: 0.875rem;
  color: #9ca3af;
  font-weight: 500;
}

.step-badge.active {
  color: #2563eb;
  font-weight: 600;
}

.step-arrow {
  color: #d1d5db;
}

.info-banner {
  display: flex;
  gap: 0.75rem;
  padding: 1rem;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  margin-bottom: 1.5rem;
}

.info-banner .pi-info-circle {
  color: #2563eb;
  font-size: 1.25rem;
  margin-top: 0.125rem;
  flex-shrink: 0;
}

.info-content {
  flex: 1;
}

.info-title {
  font-weight: 600;
  color: #1e40af;
  margin: 0 0 0.25rem 0;
  font-size: 0.875rem;
}

.info-text {
  color: #1e40af;
  margin: 0;
  font-size: 0.813rem;
  line-height: 1.5;
}

.info-text a {
  color: #2563eb;
  text-decoration: none;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

.info-text a:hover {
  text-decoration: underline;
}

.info-text a .pi-external-link {
  font-size: 0.75rem;
}

.field {
  margin-bottom: 1rem;
}

.field label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.4rem;
  font-weight: 500;
  font-size: 0.875rem;
}

.auto-filled-icon {
  color: #16a34a;
  font-size: 0.875rem;
}

.field-filled {
  border-color: #16a34a !important;
  border-width: 2px !important;
}

.w-full {
  width: 100%;
}

.summary {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  background: #f9fafb;
  padding: 1rem;
  border-radius: 8px;
  margin-bottom: 1.5rem;
}

.summary-info p {
  margin-bottom: 0.25rem;
  font-size: 0.875rem;
}

.summary-info p:last-child {
  margin-bottom: 0;
}

.placeholders-form {
  margin-bottom: 2rem;
}

.screenshots-section {
  margin-top: 2rem;
  padding-top: 2rem;
  border-top: 2px solid #e5e7eb;
}

.screenshots-title {
  font-size: 1.125rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
  color: #1f2937;
}

.screenshots-hint {
  font-size: 0.875rem;
  color: #6b7280;
  margin-bottom: 1.5rem;
  line-height: 1.5;
}

.screenshots-hint small {
  color: #9ca3af;
  font-size: 0.813rem;
}

.screenshots-hint code {
  background: #f3f4f6;
  padding: 0.125rem 0.375rem;
  border-radius: 3px;
  font-family: 'Courier New', monospace;
  font-size: 0.813rem;
  color: #374151;
}

.screenshot-field {
  margin-bottom: 1.5rem;
}

.screenshot-field label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
  font-size: 0.875rem;
  color: #374151;
}

.screenshot-label {
  display: inline-block;
  background: #f3f4f6;
  padding: 0.25rem 0.75rem;
  border-radius: 6px;
  border-left: 3px solid #2563eb;
}

.screenshot-upload-area {
  position: relative;
  border: 2px dashed #d1d5db;
  border-radius: 8px;
  padding: 2rem;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s;
  min-height: 150px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.screenshot-upload-area:hover {
  border-color: #2563eb;
  background: #f9fafb;
}

.screenshot-upload-area:focus {
  outline: none;
  border-color: #2563eb;
  background: #eff6ff;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.screenshot-upload-area.has-file {
  border-style: solid;
  border-color: #16a34a;
  padding: 1rem;
}

.upload-prompt {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
}

.upload-prompt i {
  font-size: 2rem;
  color: #9ca3af;
}

.upload-prompt p {
  color: #6b7280;
  font-size: 0.875rem;
  margin: 0;
}

.upload-prompt .or-text {
  color: #9ca3af;
  font-weight: 500;
  margin: 0.25rem 0;
}

.upload-prompt .browse-btn {
  pointer-events: all;
}

.file-input-hidden {
  display: none;
}

.uploaded-preview {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
}

.uploaded-preview img {
  max-width: 100%;
  max-height: 300px;
  border-radius: 4px;
}

.uploaded-preview .remove-btn {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
}

.no-placeholders {
  padding: 1rem;
  background: #fffbeb;
  border-radius: 8px;
  margin-bottom: 1rem;
  font-size: 0.875rem;
  color: #92400e;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding-top: 1.5rem;
  border-top: 1px solid #e5e7eb;
}
</style>
