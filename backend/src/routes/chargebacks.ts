import { FastifyInstance } from 'fastify'
import pool from '../db/pool.js'
import { duplicateAndFill } from '../services/google.js'
import { authHook } from '../plugins/auth.js'
import { pipeline } from 'stream/promises'
import fs from 'fs/promises'
import path from 'path'
import { createWriteStream } from 'fs'

export default async function chargebackRoutes(fastify: FastifyInstance) {
  // List chargebacks
  fastify.get('/api/chargebacks', { preHandler: authHook }, async (request, reply) => {
    const result = await pool.query(
      `SELECT c.*, u.display_name as created_by_name
       FROM chargebacks c
       JOIN users u ON u.id = c.created_by
       ORDER BY c.created_at DESC`
    )
    reply.send({ chargebacks: result.rows })
  })

  // Get single chargeback
  fastify.get('/api/chargebacks/:id', { preHandler: authHook }, async (request, reply) => {
    const { id } = request.params as { id: string }
    const result = await pool.query(
      `SELECT c.*, u.display_name as created_by_name
       FROM chargebacks c
       JOIN users u ON u.id = c.created_by
       WHERE c.id = $1`,
      [id]
    )
    if (result.rows.length === 0) {
      return reply.status(404).send({ error: 'Chargeback not found' })
    }
    reply.send({ chargeback: result.rows[0] })
  })

  // Generate new chargeback
  fastify.post('/api/chargebacks', { preHandler: authHook }, async (request, reply) => {
    const isMultipart = request.isMultipart()

    let templateId: string
    let templateName: string
    let orderId: string
    let title: string | undefined
    let placeholders: Record<string, string> = {}
    const screenshots: Record<string, string> = {} // Map placeholder key to file path

    if (isMultipart) {
      // Handle FormData with file uploads
      const parts = request.parts()
      const uploadsDir = path.join(process.cwd(), 'uploads')
      await fs.mkdir(uploadsDir, { recursive: true })

      for await (const part of parts) {
        if (part.type === 'field') {
          const fieldName = part.fieldname
          const value = (part as any).value

          if (fieldName === 'templateId') templateId = value
          else if (fieldName === 'templateName') templateName = value
          else if (fieldName === 'orderId') orderId = value
          else if (fieldName === 'title') title = value
          else if (fieldName === 'placeholders') {
            placeholders = JSON.parse(value)
          }
        } else if (part.type === 'file') {
          // Handle screenshot uploads
          const fieldName = part.fieldname
          if (fieldName.startsWith('screenshot:')) {
            const placeholderKey = fieldName.substring('screenshot:'.length)
            const filename = `${Date.now()}-${part.filename}`
            const filepath = path.join(uploadsDir, filename)

            await pipeline(part.file, createWriteStream(filepath))
            screenshots[placeholderKey] = filepath
            fastify.log.info(`Saved screenshot for ${placeholderKey} to ${filepath}`)
          }
        }
      }
    } else {
      // Handle JSON body (backward compatibility)
      const body = request.body as {
        templateId: string
        templateName: string
        orderId: string
        title?: string
        placeholders?: Record<string, string>
      }
      templateId = body.templateId
      templateName = body.templateName
      orderId = body.orderId
      title = body.title
      placeholders = body.placeholders || {}
    }

    if (!templateId! || !orderId!) {
      return reply.status(400).send({ error: 'templateId and orderId are required' })
    }

    const chargebackTitle = title || `Chargeback #${orderId}`

    // Merge placeholders with backward-compat {{ORDER_ID}}
    const allPlaceholders: Record<string, string> = {
      ...placeholders,
      '{{ORDER_ID}}': orderId,
    }

    // Duplicate template & replace all placeholders, including screenshots
    const { docId, docUrl } = await duplicateAndFill(templateId, chargebackTitle, allPlaceholders, screenshots)

    // Cleanup temporary screenshot files
    for (const filepath of Object.values(screenshots)) {
      try {
        await fs.unlink(filepath)
      } catch (err) {
        fastify.log.warn(`Failed to delete temp file ${filepath}:`, err)
      }
    }

    // Store in DB
    const result = await pool.query(
      `INSERT INTO chargebacks (title, order_id, template_id, template_name, google_doc_url, google_doc_id, created_by)
       VALUES ($1, $2, $3, $4, $5, $6, $7)
       RETURNING *`,
      [chargebackTitle, orderId, templateId!, templateName! || 'Unknown', docUrl, docId, request.user!.userId]
    )

    reply.status(201).send({ chargeback: result.rows[0] })
  })

  // Delete chargeback
  fastify.delete('/api/chargebacks/:id', { preHandler: authHook }, async (request, reply) => {
    if (request.user!.role !== 'admin') {
      return reply.status(403).send({ error: 'Admin only' })
    }
    const { id } = request.params as { id: string }
    const result = await pool.query('DELETE FROM chargebacks WHERE id = $1 RETURNING id', [id])
    if (result.rows.length === 0) {
      return reply.status(404).send({ error: 'Chargeback not found' })
    }
    reply.send({ ok: true })
  })
}
