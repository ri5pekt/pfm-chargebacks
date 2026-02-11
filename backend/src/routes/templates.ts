import { FastifyInstance } from 'fastify'
import { listTemplates, getPlaceholders } from '../services/google.js'
import { authHook } from '../plugins/auth.js'

let cache: { data: any; ts: number } | null = null
const CACHE_TTL = 5 * 60 * 1000 // 5 minutes

export default async function templateRoutes(fastify: FastifyInstance) {
  fastify.get('/api/templates', { preHandler: authHook }, async (_request, reply) => {
    if (cache && Date.now() - cache.ts < CACHE_TTL) {
      return reply.send({ templates: cache.data })
    }
    const templates = await listTemplates()
    cache = { data: templates, ts: Date.now() }
    reply.send({ templates })
  })

  fastify.get('/api/templates/:id/placeholders', { preHandler: authHook }, async (request, reply) => {
    const { id } = request.params as { id: string }
    try {
      const placeholders = await getPlaceholders(id)
      reply.send({ placeholders })
    } catch (err: any) {
      request.log.error(err)
      reply.status(500).send({ error: 'Failed to read template placeholders' })
    }
  })
}
