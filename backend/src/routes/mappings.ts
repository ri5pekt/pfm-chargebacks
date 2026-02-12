import { FastifyInstance } from 'fastify'
import pool from '../db/pool.js'
import { authHook } from '../plugins/auth.js'

export default async function mappingsRoutes(fastify: FastifyInstance) {
  // List all mappings (field keys are free-form: woo_order_get_* or woo_order_meta_*)
  fastify.get('/api/settings/mappings', { preHandler: authHook }, async (_request, reply) => {
    const result = await pool.query('SELECT * FROM placeholder_mappings ORDER BY placeholder')
    reply.send({ mappings: result.rows })
  })

  // Update a single mapping
  fastify.patch('/api/settings/mappings/:id', { preHandler: authHook }, async (request, reply) => {
    const { id } = request.params as { id: string }
    const { woo_field } = request.body as { woo_field: string | null }
    const result = await pool.query(
      'UPDATE placeholder_mappings SET woo_field = $1 WHERE id = $2 RETURNING *',
      [woo_field || null, id]
    )
    if (result.rows.length === 0) {
      return reply.status(404).send({ error: 'Mapping not found' })
    }
    reply.send({ mapping: result.rows[0] })
  })

  // Delete a mapping
  fastify.delete('/api/settings/mappings/:id', { preHandler: authHook }, async (request, reply) => {
    const { id } = request.params as { id: string }
    await pool.query('DELETE FROM placeholder_mappings WHERE id = $1', [id])
    reply.send({ ok: true })
  })

  // Sync: accept a list of placeholders, insert any that are new
  fastify.post('/api/settings/mappings/sync', { preHandler: authHook }, async (request, reply) => {
    const { placeholders } = request.body as { placeholders: string[] }
    if (!placeholders || placeholders.length === 0) {
      return reply.send({ added: 0 })
    }

    // Filter out screenshot placeholders - they don't need WooCommerce field mappings
    const textPlaceholders = placeholders.filter((p) => !p.toLowerCase().includes('screenshot'))

    let added = 0
    for (const p of textPlaceholders) {
      const lower = p.toLowerCase()
      const existing = await pool.query(
        'SELECT id FROM placeholder_mappings WHERE LOWER(placeholder) = $1',
        [lower]
      )
      if (existing.rows.length === 0) {
        // Try to auto-detect the woo field
        const autoField = autoDetectField(p)
        await pool.query(
          'INSERT INTO placeholder_mappings (placeholder, woo_field) VALUES ($1, $2)',
          [p, autoField]
        )
        added++
      }
    }
    reply.send({ added })
  })
}

/**
 * Auto-suggest field key from placeholder text.
 * All getters must exist on WC_Order as get_<suffix>() (see woo_order_get_* in plugin).
 * Use woo_order_meta_<key> for custom order meta (e.g. tracking).
 */
function autoDetectField(placeholder: string): string | null {
  const inner = placeholder.slice(1, -1).toLowerCase().trim()
  const map: Record<string, string> = {
    // Order basics (WC_Order getters)
    'order number': 'woo_order_get_order_number',
    'order #': 'woo_order_get_order_number',
    'order id': 'woo_order_get_id',
    date: 'woo_order_get_date_created',
    'date of checkout': 'woo_order_get_date_created',
    'date & time': 'woo_order_get_date_paid',
    'date &time': 'woo_order_get_date_paid',
    'order total': 'woo_order_get_total',
    'disputed total': 'special_disputed_total',
    'transaction total': 'special_transaction_total',
    'transaction amount': 'special_transaction_total',
    status: 'woo_order_get_status',
    currency: 'woo_order_get_currency',
    // Customer / billing (WC_Order getters)
    'customer name': 'woo_order_get_formatted_billing_full_name',
    "customer's name": 'woo_order_get_formatted_billing_full_name',
    'enter customer name': 'woo_order_get_formatted_billing_full_name',
    'cardholder description': 'woo_order_get_formatted_billing_full_name',
    'customer email': 'woo_order_get_billing_email',
    "customer's telephone #": 'woo_order_get_billing_phone',
    'customer telephone': 'woo_order_get_billing_phone',
    'customer phone': 'woo_order_get_billing_phone',
    'ip address': 'woo_order_get_customer_ip_address',
    'billing address': 'woo_order_get_formatted_billing_address',
    'shipping address': 'woo_order_get_formatted_shipping_address',
    'shipping carrier': 'special_shipping_carrier',
    'shipping method': 'woo_order_get_shipping_method',
    'card type': 'woo_order_get_payment_method_title',
    'payment method': 'woo_order_get_payment_method_title',
    'transaction id': 'woo_order_get_transaction_id',
    'transaction ID': 'woo_order_get_transaction_id',
    'customer note': 'woo_order_get_customer_note',
    // Order meta (custom fields – adjust key if your store uses different meta)
    'tracking id': 'special_tracking_number',
    'tracking ID': 'special_tracking_number',
    'tracking number': 'special_tracking_number',
    'last 4 digits': 'woo_order_meta__braintree_card_details.last4',
    'last 4': 'woo_order_meta__braintree_card_details.last4',
    'card last 4': 'woo_order_meta__braintree_card_details.last4',
    // Special fields (complex handlers in WooCommerce plugin)
    'list products and quantities': 'special_products_and_quantities',
    'products and quantities': 'special_products_and_quantities',
    'product list': 'special_products_and_quantities',
    'products': 'special_products_and_quantities',
    'case id': 'special_case_id',
    'dispute id': 'special_case_id',
    'chargeback id': 'special_case_id',
    'reference number': 'special_reference_number',
    'dispute reference': 'special_reference_number',
    'case number': 'special_reference_number',
    'reason code number & reason': 'special_reason_code_and_reason',
    'reason code & reason': 'special_reason_code_and_reason',
    'reason code number & title': 'special_reason_code_and_title',
    'reason code & title': 'special_reason_code_and_title',
    'insert claim reason': 'special_dispute_reason',
    'claim reason': 'special_dispute_reason',
    'dispute reason': 'special_dispute_reason',
    'insert claim': 'special_dispute_reason_description',
    'claim description': 'special_dispute_reason_description',
    'reason description': 'special_dispute_reason_description',
    'date of first order': 'special_subscription_first_order_date',
    'date of parent order': 'special_subscription_first_order_date',
    'subscription first order date': 'special_subscription_first_order_date',
    'parent order date': 'special_subscription_first_order_date',
    'number of months': 'special_subscription_billing_months',
    'billing months': 'special_subscription_billing_months',
    'subscription months': 'special_subscription_billing_months',
    'product': 'special_ppu_product_name',
    'ppu product': 'special_ppu_product_name',
    'upsell product': 'special_ppu_product_name',
  }
  return map[inner] || null
}
