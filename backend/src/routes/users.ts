import { FastifyInstance } from 'fastify'
import bcrypt from 'bcrypt'
import pool from '../db/pool.js'
import { authHook } from '../plugins/auth.js'

export default async function userRoutes(fastify: FastifyInstance) {
  // List all users (admin only)
  fastify.get('/api/users', { preHandler: authHook }, async (request, reply) => {
    if (request.user!.role !== 'admin') {
      return reply.status(403).send({ error: 'Admin only' })
    }

    const result = await pool.query(
      'SELECT id, email, display_name, role, created_at FROM users ORDER BY created_at DESC'
    )
    reply.send({ users: result.rows })
  })

  // Create new user (admin only)
  fastify.post('/api/users', { preHandler: authHook }, async (request, reply) => {
    if (request.user!.role !== 'admin') {
      return reply.status(403).send({ error: 'Admin only' })
    }

    const { email, password, displayName, role } = request.body as {
      email: string
      password: string
      displayName: string
      role: string
    }

    if (!email || !password || !displayName) {
      return reply.status(400).send({ error: 'Email, password, and display name are required' })
    }

    // Check if user already exists
    const existing = await pool.query('SELECT id FROM users WHERE email = $1', [email])
    if (existing.rows.length > 0) {
      return reply.status(400).send({ error: 'User with this email already exists' })
    }

    const passwordHash = await bcrypt.hash(password, 10)
    const result = await pool.query(
      'INSERT INTO users (email, password_hash, display_name, role) VALUES ($1, $2, $3, $4) RETURNING id, email, display_name, role, created_at',
      [email, passwordHash, displayName, role || 'user']
    )

    reply.status(201).send({ user: result.rows[0] })
  })

  // Delete user (admin only)
  fastify.delete('/api/users/:id', { preHandler: authHook }, async (request, reply) => {
    if (request.user!.role !== 'admin') {
      return reply.status(403).send({ error: 'Admin only' })
    }

    const { id } = request.params as { id: string }

    // Prevent deleting yourself
    if (parseInt(id) === request.user!.userId) {
      return reply.status(400).send({ error: 'Cannot delete your own account' })
    }

    const result = await pool.query('DELETE FROM users WHERE id = $1 RETURNING id', [id])
    if (result.rows.length === 0) {
      return reply.status(404).send({ error: 'User not found' })
    }

    reply.send({ ok: true })
  })

  // Change own password
  fastify.patch('/api/users/me/password', { preHandler: authHook }, async (request, reply) => {
    const { password } = request.body as { password: string }

    if (!password || password.length < 6) {
      return reply.status(400).send({ error: 'Password must be at least 6 characters' })
    }

    const passwordHash = await bcrypt.hash(password, 10)
    await pool.query('UPDATE users SET password_hash = $1 WHERE id = $2', [passwordHash, request.user!.userId])

    reply.send({ ok: true })
  })
}
