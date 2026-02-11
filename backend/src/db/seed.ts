import bcrypt from 'bcrypt'
import pool from './pool.js'

export async function seed() {
  const existing = await pool.query('SELECT id FROM users WHERE email = $1', ['admin@pfm.com'])
  if (existing.rows.length > 0) return

  const hash = await bcrypt.hash('admin123', 10)
  await pool.query(
    'INSERT INTO users (email, password_hash, display_name, role) VALUES ($1, $2, $3, $4)',
    ['admin@pfm.com', hash, 'Admin', 'admin']
  )
  console.log('Seed: admin user created (admin@pfm.com / admin123)')
}
