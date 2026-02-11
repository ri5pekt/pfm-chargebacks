import { FastifyInstance } from 'fastify'
import bcrypt from 'bcrypt'
import pool from '../db/pool.js'
import { signToken, authHook } from '../plugins/auth.js'

export default async function authRoutes(fastify: FastifyInstance) {
  fastify.post('/api/auth/login', async (request, reply) => {
    const { email, password } = request.body as { email: string; password: string }
    if (!email || !password) {
      return reply.status(400).send({ error: 'Email and password required' })
    }

    const result = await pool.query('SELECT * FROM users WHERE email = $1', [email])
    if (result.rows.length === 0) {
      return reply.status(401).send({ error: 'Invalid credentials' })
    }

    const user = result.rows[0]
    const valid = await bcrypt.compare(password, user.password_hash)
    if (!valid) {
      return reply.status(401).send({ error: 'Invalid credentials' })
    }

    const token = signToken({ userId: user.id, email: user.email, role: user.role })

    reply
      .setCookie('token', token, {
        path: '/',
        httpOnly: true,
        sameSite: 'strict',
        maxAge: 7 * 24 * 60 * 60,
      })
      .send({ user: { id: user.id, email: user.email, displayName: user.display_name, role: user.role } })
  })

  fastify.post('/api/auth/logout', async (_request, reply) => {
    reply.clearCookie('token', { path: '/' }).send({ ok: true })
  })

  fastify.get('/api/auth/me', { preHandler: authHook }, async (request, reply) => {
    const result = await pool.query('SELECT id, email, display_name, role FROM users WHERE id = $1', [request.user!.userId])
    if (result.rows.length === 0) {
      return reply.status(401).send({ error: 'User not found' })
    }
    const u = result.rows[0]
    reply.send({ user: { id: u.id, email: u.email, displayName: u.display_name, role: u.role } })
  })
}
