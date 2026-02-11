import { google } from 'googleapis'
import pool from '../db/pool.js'
import fs from 'fs'
import path from 'path'

const oauth2Client = new google.auth.OAuth2(
  process.env.GOOGLE_CLIENT_ID,
  process.env.GOOGLE_CLIENT_SECRET,
  process.env.GOOGLE_REDIRECT_URI
)

const SCOPES = [
  'https://www.googleapis.com/auth/drive.file',
  'https://www.googleapis.com/auth/documents',
  'https://www.googleapis.com/auth/drive',
]

export function getAuthUrl(): string {
  return oauth2Client.generateAuthUrl({
    access_type: 'offline',
    prompt: 'consent',
    scope: SCOPES,
  })
}

export async function handleCallback(code: string) {
  const { tokens } = await oauth2Client.getToken(code)
  oauth2Client.setCredentials(tokens)

  // Upsert tokens
  const existing = await pool.query('SELECT id FROM google_tokens LIMIT 1')
  if (existing.rows.length > 0) {
    await pool.query(
      'UPDATE google_tokens SET access_token=$1, refresh_token=$2, expiry_date=$3, updated_at=now() WHERE id=$4',
      [tokens.access_token, tokens.refresh_token, tokens.expiry_date, existing.rows[0].id]
    )
  } else {
    await pool.query(
      'INSERT INTO google_tokens (access_token, refresh_token, expiry_date) VALUES ($1, $2, $3)',
      [tokens.access_token, tokens.refresh_token, tokens.expiry_date]
    )
  }
  return tokens
}

export async function getAuthedClient() {
  const result = await pool.query('SELECT * FROM google_tokens LIMIT 1')
  if (result.rows.length === 0) {
    throw new Error('Google not connected. Please authorize via /api/oauth/google/start')
  }
  const row = result.rows[0]
  oauth2Client.setCredentials({
    access_token: row.access_token,
    refresh_token: row.refresh_token,
    expiry_date: Number(row.expiry_date),
  })

  // Listen for token refresh
  oauth2Client.on('tokens', async (newTokens) => {
    const updates: string[] = []
    const values: any[] = []
    let idx = 1
    if (newTokens.access_token) {
      updates.push(`access_token=$${idx++}`)
      values.push(newTokens.access_token)
    }
    if (newTokens.refresh_token) {
      updates.push(`refresh_token=$${idx++}`)
      values.push(newTokens.refresh_token)
    }
    if (newTokens.expiry_date) {
      updates.push(`expiry_date=$${idx++}`)
      values.push(newTokens.expiry_date)
    }
    if (updates.length > 0) {
      updates.push(`updated_at=now()`)
      values.push(row.id)
      await pool.query(
        `UPDATE google_tokens SET ${updates.join(', ')} WHERE id=$${idx}`,
        values
      )
    }
  })

  return oauth2Client
}

export async function listTemplates() {
  const auth = await getAuthedClient()
  const drive = google.drive({ version: 'v3', auth })

  const folderId = process.env.GOOGLE_TEMPLATES_FOLDER_ID
  const res = await drive.files.list({
    q: `'${folderId}' in parents and mimeType='application/vnd.google-apps.document' and trashed=false`,
    fields: 'files(id, name, modifiedTime)',
    orderBy: 'name',
    supportsAllDrives: true,
    includeItemsFromAllDrives: true,
  })

  return res.data.files || []
}

export async function getPlaceholders(templateId: string): Promise<string[]> {
  const auth = await getAuthedClient()
  const docs = google.docs({ version: 'v1', auth })

  const doc = await docs.documents.get({ documentId: templateId })

  const textRuns: string[] = []

  function extractText(elements: any[]) {
    for (const el of elements) {
      if (el.paragraph) {
        for (const pe of el.paragraph.elements || []) {
          if (pe.textRun?.content) textRuns.push(pe.textRun.content)
        }
      }
      if (el.table) {
        for (const row of el.table.tableRows || []) {
          for (const cell of row.tableCells || []) {
            extractText(cell.content || [])
          }
        }
      }
    }
  }

  extractText(doc.data.body?.content || [])

  const fullText = textRuns.join('')
  const regex = /\[([^\]]+)\]/g
  const seen = new Map<string, string>() // lowercase -> first occurrence
  let match: RegExpExecArray | null
  while ((match = regex.exec(fullText)) !== null) {
    const key = match[0].toLowerCase()
    if (!seen.has(key)) {
      seen.set(key, match[0])
    }
  }

  return Array.from(seen.values()).sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()))
}

async function handleScreenshots(documentId: string, screenshots: Record<string, string>, auth: any) {
  const docs = google.docs({ version: 'v1', auth })
  const drive = google.drive({ version: 'v3', auth })

  // Get the current document to find screenshot placeholder locations
  const doc = await docs.documents.get({ documentId })

  const requests: any[] = []

  // Find all screenshot placeholders in the document
  const placeholderLocations: { key: string; startIndex: number; endIndex: number }[] = []

  function findPlaceholders(elements: any[], offset = 1) {
    let currentOffset = offset
    for (const el of elements) {
      if (el.paragraph) {
        for (const pe of el.paragraph.elements || []) {
          if (pe.textRun?.content) {
            const text = pe.textRun.content
            const regex = /\[([^\]]+)\]/g
            let match: RegExpExecArray | null
            while ((match = regex.exec(text)) !== null) {
              const placeholder = match[0]
              if (placeholder.toLowerCase().includes('screenshot')) {
                placeholderLocations.push({
                  key: placeholder,
                  startIndex: currentOffset + match.index,
                  endIndex: currentOffset + match.index + placeholder.length,
                })
              }
            }
            currentOffset += text.length
          } else {
            currentOffset += 1 // For non-text elements
          }
        }
      }
      if (el.table) {
        for (const row of el.table.tableRows || []) {
          for (const cell of row.tableCells || []) {
            currentOffset = findPlaceholders(cell.content || [], currentOffset)
          }
        }
      }
    }
    return currentOffset
  }

  findPlaceholders(doc.data.body?.content || [])

  // Process placeholders in reverse order (to maintain correct indices)
  placeholderLocations.sort((a, b) => b.startIndex - a.startIndex)

  for (const placeholder of placeholderLocations) {
    const imagePath = screenshots[placeholder.key]

    if (imagePath && fs.existsSync(imagePath)) {
      // Upload image to Google Drive
      const imageFile = await drive.files.create({
        requestBody: {
          name: path.basename(imagePath),
          mimeType: 'image/jpeg',
        },
        media: {
          mimeType: 'image/jpeg',
          body: fs.createReadStream(imagePath),
        },
        fields: 'id',
      })

      const imageId = imageFile.data.id!

      // Make the image accessible
      await drive.permissions.create({
        fileId: imageId,
        requestBody: {
          type: 'anyone',
          role: 'reader',
        },
      })

      // Get the image URL
      const imageUrl = `https://drive.google.com/uc?id=${imageId}`

      // Delete placeholder text
      requests.push({
        deleteContentRange: {
          range: {
            startIndex: placeholder.startIndex,
            endIndex: placeholder.endIndex,
          },
        },
      })

      // Insert image at the placeholder location
      requests.push({
        insertInlineImage: {
          uri: imageUrl,
          location: {
            index: placeholder.startIndex,
          },
          objectSize: {
            height: { magnitude: 300, unit: 'PT' },
            width: { magnitude: 400, unit: 'PT' },
          },
        },
      })
    } else {
      // No image provided, just remove the placeholder
      requests.push({
        deleteContentRange: {
          range: {
            startIndex: placeholder.startIndex,
            endIndex: placeholder.endIndex,
          },
        },
      })
    }
  }

  if (requests.length > 0) {
    await docs.documents.batchUpdate({
      documentId,
      requestBody: { requests },
    })
  }
}

export async function duplicateAndFill(
  templateId: string,
  title: string,
  placeholders: Record<string, string>,
  screenshots: Record<string, string> = {}
) {
  const auth = await getAuthedClient()
  const drive = google.drive({ version: 'v3', auth })
  const docs = google.docs({ version: 'v1', auth })

  const generatedFolderId = process.env.GOOGLE_GENERATED_FOLDER_ID

  // 1. Copy template
  const copy = await drive.files.copy({
    fileId: templateId,
    requestBody: {
      name: title,
      parents: generatedFolderId ? [generatedFolderId] : undefined,
    },
    supportsAllDrives: true,
  })

  const newDocId = copy.data.id!

  // 2. Replace all text placeholders
  const textRequests = Object.entries(placeholders)
    .filter(([, value]) => value !== '')
    .map(([placeholder, value]) => ({
      replaceAllText: {
        containsText: { text: placeholder, matchCase: false },
        replaceText: value,
      },
    }))

  if (textRequests.length > 0) {
    await docs.documents.batchUpdate({
      documentId: newDocId,
      requestBody: { requests: textRequests },
    })
  }

  // 3. Handle screenshot placeholders
  if (Object.keys(screenshots).length > 0) {
    await handleScreenshots(newDocId, screenshots, auth)
  }

  const docUrl = `https://docs.google.com/document/d/${newDocId}/edit`
  return { docId: newDocId, docUrl }
}

export async function isConnected(): Promise<boolean> {
  const result = await pool.query('SELECT id FROM google_tokens LIMIT 1')
  return result.rows.length > 0
}
