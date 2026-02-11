import Fastify from 'fastify'
import cookie from '@fastify/cookie'
import cors from '@fastify/cors'
import multipart from '@fastify/multipart'
import authPlugin from './plugins/auth.js'
import authRoutes from './routes/auth.js'
import oauthRoutes from './routes/oauth.js'
import templateRoutes from './routes/templates.js'
import chargebackRoutes from './routes/chargebacks.js'
import woocommerceRoutes from './routes/woocommerce.js'
import mappingsRoutes from './routes/mappings.js'
import userRoutes from './routes/users.js'
import { runMigrations } from './db/migrate.js'
import { seed } from './db/seed.js'
import { readFileSync } from 'fs'
import { join, dirname } from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

const fastify = Fastify({ logger: true })

// Read version from package.json
const packageJson = JSON.parse(
  readFileSync(join(__dirname, '../package.json'), 'utf-8')
)
const VERSION = packageJson.version

async function start() {
  // Plugins
  await fastify.register(cors, {
    origin: true,
    credentials: true,
  })
  await fastify.register(cookie)
  await fastify.register(multipart, {
    limits: {
      fileSize: 10 * 1024 * 1024, // 10MB max file size
    },
  })
  await fastify.register(authPlugin)

  // Routes
  await fastify.register(authRoutes)
  await fastify.register(oauthRoutes)
  await fastify.register(templateRoutes)
  await fastify.register(chargebackRoutes)
  await fastify.register(woocommerceRoutes)
  await fastify.register(mappingsRoutes)
  await fastify.register(userRoutes)

  // Health check
  fastify.get('/health', async () => ({ status: 'ok' }))

  // Version endpoint
  fastify.get('/api/version', async () => ({ version: VERSION }))

  // DB setup
  await runMigrations()
  await seed()

  // Start
  await fastify.listen({ port: 3000, host: '0.0.0.0' })
  console.log(`Backend running on http://0.0.0.0:3000 (v${VERSION})`)
}

start().catch((err) => {
  console.error(err)
  process.exit(1)
})
