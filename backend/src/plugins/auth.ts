import { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify'
import jwt from 'jsonwebtoken'

const JWT_SECRET = process.env.JWT_SECRET || 'changeme'

export interface JwtPayload {
  userId: number
  email: string
  role: string
}

declare module 'fastify' {
  interface FastifyRequest {
    user?: JwtPayload
  }
}

export function signToken(payload: JwtPayload): string {
  return jwt.sign(payload, JWT_SECRET, { expiresIn: '7d' })
}

export function verifyToken(token: string): JwtPayload {
  return jwt.verify(token, JWT_SECRET) as JwtPayload
}

export async function authHook(request: FastifyRequest, reply: FastifyReply) {
  const token = request.cookies?.token
  if (!token) {
    reply.status(401).send({ error: 'Not authenticated' })
    return
  }
  try {
    request.user = verifyToken(token)
  } catch {
    reply.status(401).send({ error: 'Invalid token' })
  }
}

export default async function authPlugin(fastify: FastifyInstance) {
  fastify.decorateRequest('user', undefined)
}
