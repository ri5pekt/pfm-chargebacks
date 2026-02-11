import { FastifyInstance } from 'fastify'
import { getAuthUrl, handleCallback } from '../services/google.js'
import { authHook } from '../plugins/auth.js'

export default async function oauthRoutes(fastify: FastifyInstance) {
  fastify.get('/api/oauth/google/start', { preHandler: authHook }, async (_request, reply) => {
    const url = getAuthUrl()
    reply.redirect(url)
  })

  fastify.get('/oauth/google/callback', async (request, reply) => {
    const { code } = request.query as { code: string }
    if (!code) {
      return reply.status(400).send({ error: 'Missing code' })
    }
    await handleCallback(code)
    reply.redirect('http://localhost:5173/chargebacks?google=connected')
  })

  fastify.get('/api/oauth/google/status', { preHandler: authHook }, async (_request, reply) => {
    const { isConnected } = await import('../services/google.js')
    const connected = await isConnected()
    reply.send({ connected })
  })
}
