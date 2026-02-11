# Phase 1 — Development Plan

## Goal

Stand up the full stack (Docker, Postgres, Fastify API, Vue 3 dashboard) and deliver the **Google Drive integration loop**: list templates → duplicate a template → replace a test placeholder → save to "Generated" folder → store the record in Postgres → display it in the dashboard.

---

## Step 1 — Project scaffolding & Docker environment

**What:** Create the mono-repo folder structure, Docker Compose config, and bare "hello world" services.

```
pfm-chargebacks-automation/
├── docker-compose.yml
├── backend/
│   ├── Dockerfile
│   ├── package.json
│   ├── tsconfig.json
│   └── src/
│       └── index.ts          # Fastify entry point
├── frontend/
│   ├── Dockerfile
│   ├── package.json
│   ├── vite.config.ts
│   └── src/
│       └── main.ts           # Vue 3 entry point
└── docs/                     # (existing)
```

Docker Compose services:
| Service    | Image / Build       | Ports         |
|------------|---------------------|---------------|
| `db`       | postgres:16-alpine  | 5432 → 5432   |
| `backend`  | ./backend           | 3000 → 3000   |
| `frontend` | ./frontend (Vite)   | 5173 → 5173   |

**Deliverable:** `docker compose up` starts all three services; backend responds on `/health`, frontend shows a blank page.

---

## Step 2 — Database schema & migration

**What:** Create the PostgreSQL tables needed for Phase 1.

```sql
-- users (seeded, no registration in Phase 1)
CREATE TABLE users (
  id            SERIAL PRIMARY KEY,
  email         VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(255) NOT NULL,
  role          VARCHAR(50)  NOT NULL DEFAULT 'admin',
  created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- chargebacks
CREATE TABLE chargebacks (
  id            SERIAL PRIMARY KEY,
  title         VARCHAR(255) NOT NULL,
  order_id      VARCHAR(100) NOT NULL,
  template_id   VARCHAR(255) NOT NULL,   -- Google Drive file ID
  template_name VARCHAR(255) NOT NULL,
  google_doc_url TEXT,
  google_doc_id  VARCHAR(255),
  status        VARCHAR(50)  NOT NULL DEFAULT 'generated',
  created_by    INT          NOT NULL REFERENCES users(id),
  created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
```

Simple file-based migration runner (`backend/src/db/migrate.ts`) executed on app start.

**Deliverable:** Tables exist after first backend boot; seed script creates one admin user.

---

## Step 3 — Auth (JWT + httpOnly cookie)

**What:** Implement login/logout and route protection.

### API endpoints

| Method | Path              | Description              |
|--------|-------------------|--------------------------|
| POST   | `/api/auth/login` | Accepts email + password, returns httpOnly JWT cookie |
| POST   | `/api/auth/logout`| Clears the cookie        |
| GET    | `/api/auth/me`    | Returns current user info |

### Details
- Passwords hashed with **bcrypt**.
- JWT stored in a **Secure, httpOnly, SameSite=Strict** cookie.
- Fastify `onRequest` hook validates the JWT on all `/api/*` routes (except login).
- No registration endpoint — admin user is seeded via migration.

### Frontend
- Login page (`/login`) with email + password form.
- Vue Router navigation guard redirects unauthenticated users to `/login`.
- Pinia `authStore` holds the current user object (fetched from `/api/auth/me` on app load).

**Deliverable:** Login works end-to-end; protected routes redirect to login.

---

## Step 4 — Google OAuth 2.0 (service-level token)

**What:** Connect the backend to Google Drive & Docs APIs using the existing OAuth credentials.

### Approach
- Use the **OAuth 2.0 Web Application** flow with the credentials already in `docs/google-auth/`.
- Backend endpoint `/api/oauth/google/start` redirects admin to Google consent screen.
- Callback `/oauth/google/callback` exchanges the code for access + refresh tokens.
- Tokens stored encrypted in Postgres (new `google_tokens` table) or an env/config file.
- A helper module (`backend/src/services/google.ts`) wraps `googleapis` SDK and handles automatic token refresh.
- Scopes needed: `drive.file`, `documents`.

**Deliverable:** Backend can make authenticated calls to Google Drive/Docs APIs.

---

## Step 5 — Templates endpoint (list from Google Drive)

**What:** API to list available Google Docs templates from a configured Shared Drive folder.

| Method | Path               | Description                              |
|--------|--------------------|------------------------------------------|
| GET    | `/api/templates`   | Lists Google Docs in the templates folder |

### Details
- The "Templates" folder ID is stored in an env variable (`GOOGLE_TEMPLATES_FOLDER_ID`).
- Response shape: `{ templates: [{ id, name, modifiedTime }] }`.
- Results are cached in-memory for 5 minutes to reduce API calls.

**Deliverable:** Frontend can fetch and display a dropdown of available templates.

---

## Step 6 — Chargeback generation (core flow)

**What:** The "New Chargeback" modal submits to the backend which duplicates the template, replaces a test placeholder, and moves the copy to the "Generated" folder.

| Method | Path                  | Description                     |
|--------|-----------------------|---------------------------------|
| POST   | `/api/chargebacks`    | Generate a new chargeback doc   |

### Backend logic (service layer)
1. **Copy** the selected template via `drive.files.copy()` with a new title.
2. **Replace** a single test placeholder `{{ORDER_ID}}` in the document body via `docs.documents.batchUpdate()` with a `replaceAllText` request.
3. **Move** the new file into the "Generated" folder (env var `GOOGLE_GENERATED_FOLDER_ID`).
4. **Insert** a row into the `chargebacks` table with all metadata + the new Google Doc URL.
5. Return the created chargeback record.

### Request body
```json
{
  "templateId": "<google-drive-file-id>",
  "orderId": "12345",
  "title": "Chargeback #12345"     // optional, auto-generated if omitted
}
```

**Deliverable:** Hitting "Generate" creates a real Google Doc in the Generated folder with the placeholder replaced.

---

## Step 7 — Chargebacks CRUD API

**What:** Endpoints for listing, viewing, and removing chargebacks.

| Method | Path                     | Description                  |
|--------|--------------------------|------------------------------|
| GET    | `/api/chargebacks`       | List all (paginated)         |
| GET    | `/api/chargebacks/:id`   | Get single record            |
| DELETE | `/api/chargebacks/:id`   | Soft-delete / remove record  |

**Deliverable:** Full API for the frontend to consume.

---

## Step 8 — Frontend screens

### 8a. Chargebacks list (`/chargebacks`)
- Table (PrimeVue DataTable) with columns: Title, Order ID, Template, Created by, Created date, Doc link, Status.
- "New Chargeback" button opens modal.
- Row actions: **Open** (navigates to detail), **Remove** (admin-only, confirms then DELETEs).

### 8b. New Chargeback modal
- Template dropdown (populated from `GET /api/templates`).
- Order ID text input.
- Title text input (default: `Chargeback #<orderId>`).
- Cancel / Generate buttons.
- On success → redirect to detail page.

### 8c. Chargeback detail (`/chargebacks/:id`)
- Displays: Title, Order ID, Template name, Created date/time, Created by, Status.
- "Open Doc" button linking to the Google Doc URL.
- "Back to list" navigation.
- "Remove" button (admin-only).

**Deliverable:** All three screens functional and connected to the API.

---

## Step 9 — Polish & wrap-up

- Environment variable documentation (`.env.example`).
- Basic error handling & loading states in the frontend.
- API error responses with consistent shape (`{ error: string }`).
- Ensure Docker Compose starts cleanly from a fresh clone.
- Verify the full flow end-to-end: login → pick template → generate → view doc.

---

## Summary of implementation order

| # | Task | Key files |
|---|------|-----------|
| 1 | Scaffolding + Docker | `docker-compose.yml`, Dockerfiles, package.json files |
| 2 | DB schema + seed | `backend/src/db/migrate.ts`, `backend/src/db/seed.ts` |
| 3 | Auth (JWT) | `backend/src/routes/auth.ts`, `frontend/src/stores/auth.ts`, login page |
| 4 | Google OAuth | `backend/src/services/google.ts`, callback route |
| 5 | Templates endpoint | `backend/src/routes/templates.ts` |
| 6 | Chargeback generation | `backend/src/services/chargebacks.ts` |
| 7 | Chargebacks CRUD | `backend/src/routes/chargebacks.ts` |
| 8 | Frontend screens | Vue components & views |
| 9 | Polish | Error handling, `.env.example`, e2e test |

---

## Environment variables needed

```env
# Database
DATABASE_URL=postgresql://postgres:postgres@db:5432/chargebacks

# Auth
JWT_SECRET=<random-string>

# Google
GOOGLE_CLIENT_ID=56439278267-6jv33urhuni1uc0tt5nclbneq5glgc4t.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=<from-credentials-file>
GOOGLE_REDIRECT_URI=http://localhost:3000/oauth/google/callback
GOOGLE_TEMPLATES_FOLDER_ID=<shared-drive-folder-id>
GOOGLE_GENERATED_FOLDER_ID=<shared-drive-folder-id>
```

> **Note:** The Google client secret is already available in `docs/google-auth/`. It should be moved to `.env` (git-ignored) before development begins.
