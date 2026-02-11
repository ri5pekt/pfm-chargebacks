import pool from './pool.js'

const migrations = [
  `CREATE TABLE IF NOT EXISTS users (
    id            SERIAL PRIMARY KEY,
    email         VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(255) NOT NULL,
    role          VARCHAR(50)  NOT NULL DEFAULT 'admin',
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
  )`,
  `CREATE TABLE IF NOT EXISTS chargebacks (
    id             SERIAL PRIMARY KEY,
    title          VARCHAR(255) NOT NULL,
    order_id       VARCHAR(100) NOT NULL,
    template_id    VARCHAR(255) NOT NULL,
    template_name  VARCHAR(255) NOT NULL,
    google_doc_url TEXT,
    google_doc_id  VARCHAR(255),
    status         VARCHAR(50)  NOT NULL DEFAULT 'generated',
    created_by     INT          NOT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
  )`,
  `CREATE TABLE IF NOT EXISTS google_tokens (
    id            SERIAL PRIMARY KEY,
    access_token  TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expiry_date   BIGINT NOT NULL,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
  )`,
  `CREATE TABLE IF NOT EXISTS placeholder_mappings (
    id            SERIAL PRIMARY KEY,
    placeholder   VARCHAR(255) UNIQUE NOT NULL,
    woo_field     VARCHAR(255),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
  )`,
]

export async function runMigrations() {
  for (const sql of migrations) {
    await pool.query(sql)
  }
  console.log('Migrations complete')
}
