import { FastifyInstance, FastifyReply } from 'fastify'
import { authHook } from '../plugins/auth.js'
import pool from '../db/pool.js'

const WOO_BASE = process.env.WOOCOMMERCE_URL || 'https://www.particleformen.com'
const WOO_TOKEN = process.env.WOOCOMMERCE_TOKEN || 'pfm-cb-2026-secret'

export default async function woocommerceRoutes(fastify: FastifyInstance) {
  // Basic order fetch (raw WooCommerce data; no fields param = preset response for backward compat)
  fastify.get('/api/woocommerce/order/:id', { preHandler: authHook }, async (request, reply) => {
    const { id } = request.params as { id: string }

    const url = `${WOO_BASE}/wp-json/pfm-chargebacks/v1/order/${id}?token=${WOO_TOKEN}&_=${Date.now()}`

    const res = await fetch(url)
    if (!res.ok) {
      const text = await res.text()
      return reply.status(res.status).send({ error: text || 'Order not found' })
    }

    const data = await res.json()
    reply.send(data)
  })

  // Order fetch with placeholder mapping: client sends template placeholders (GET query or POST body), we look up their mappings and request those fields from WooCommerce
  async function handleMappedOrder(id: string, placeholdersFromClient: string[], reply: FastifyReply) {
    // 1. Load mappings for these placeholders (case-insensitive: compare lowercase)
    const placeholdersLower = placeholdersFromClient.map((p) => p.toLowerCase())
    
    const mappingsResult =
      placeholdersLower.length > 0
        ? await pool.query(
            `SELECT placeholder, woo_field FROM placeholder_mappings
             WHERE woo_field IS NOT NULL AND LOWER(placeholder) = ANY($1)`,
            [placeholdersLower]
          )
        : await pool.query(
            'SELECT placeholder, woo_field FROM placeholder_mappings WHERE woo_field IS NOT NULL'
          )

    const fields = [...new Set(mappingsResult.rows.map((r: { woo_field: string }) => r.woo_field).filter(Boolean))]
    const fieldsParam = fields.length > 0 ? `&fields=${encodeURIComponent(fields.join(','))}` : ''

    fastify.log.info(
      {
        orderId: id,
        placeholdersFromClientCount: placeholdersFromClient.length,
        mappingsFromDbCount: mappingsResult.rows.length,
        fieldsRequested: fields,
      },
      '[woo/mapped] placeholders and mappings'
    )

    if (fields.length === 0) {
      fastify.log.warn(
        { orderId: id },
        '[woo/mapped] no woo_field mappings found – add mappings in Settings and click Update'
      )
      return reply.send({ order: {}, mapped: {} })
    }

    // 2. Request those fields from WooCommerce (GET with ?fields=... in query string)
    const cacheBuster = `&_=${Date.now()}`
    const url = `${WOO_BASE}/wp-json/pfm-chargebacks/v1/order/${id}?token=${WOO_TOKEN}${fieldsParam}${cacheBuster}`
    fastify.log.info({ orderId: id, url }, '[woo/mapped] GET request to WooCommerce')
    const res = await fetch(url)
    if (!res.ok) {
      const text = await res.text()
      fastify.log.warn({ orderId: id, status: res.status, body: text }, '[woo/mapped] WooCommerce error')
      return reply.status(res.status).send({ error: text || 'Order not found' })
    }
    const rawWoo = await res.json()
    const wooData: Record<string, string> = Array.isArray(rawWoo) ? {} : rawWoo ?? {}
    fastify.log.info({ orderId: id, wooData }, '[woo/mapped] response from WooCommerce')

    // 3. Build placeholder -> value from plugin response
    const mapped: Record<string, string> = {}
    for (const row of mappingsResult.rows) {
      const val = wooData[row.woo_field]
      if (val != null && val !== '') {
        mapped[row.placeholder] = String(val)
      }
    }
    fastify.log.info({ orderId: id, mapped }, '[woo/mapped] sending mapped to client')
    return reply.send({ order: wooData, mapped })
  }

  fastify.get('/api/woocommerce/order/:id/mapped', { preHandler: authHook }, async (request, reply) => {
    const { id } = request.params as { id: string }
    const raw = (request.query as { placeholders?: string }).placeholders
    let placeholders: string[] = []
    if (typeof raw === 'string' && raw) {
      try {
        placeholders = JSON.parse(decodeURIComponent(raw)) as string[]
        if (!Array.isArray(placeholders)) placeholders = []
      } catch {
        placeholders = []
      }
    }
    return handleMappedOrder(id, placeholders, reply)
  })

  fastify.post('/api/woocommerce/order/:id/mapped', { preHandler: authHook }, async (request, reply) => {
    const { id } = request.params as { id: string }
    const body = request.body as { placeholders?: string[] } | undefined
    const placeholders = Array.isArray(body?.placeholders) ? body.placeholders : []
    return handleMappedOrder(id, placeholders, reply)
  })
}
