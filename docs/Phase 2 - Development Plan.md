# Phase 2 — Development Plan

## Goal

Integrate **WooCommerce** so chargeback documents are automatically populated with real order and customer data. Add multi-placeholder replacement, improve UX, and harden the system for daily use.

---

## Step 1 — WooCommerce API integration

**What:** Connect to the WooCommerce REST API to fetch order details.

### Details
- New env vars: `WOOCOMMERCE_URL`, `WOOCOMMERCE_CONSUMER_KEY`, `WOOCOMMERCE_CONSUMER_SECRET`.
- New service module `backend/src/services/woocommerce.ts` using `@woocommerce/woocommerce-rest-api` or raw HTTP calls.
- `getOrder(orderId)` fetches and normalizes order data (billing, shipping, line items, totals, dates, payment method).

### API endpoint

| Method | Path                        | Description                   |
|--------|-----------------------------|-------------------------------|
| GET    | `/api/orders/:id`           | Fetch WooCommerce order data  |

**Deliverable:** Backend can retrieve and return any WooCommerce order by ID.

---

## Step 2 — Multi-placeholder template replacement

**What:** Replace all standard placeholders in the generated Google Doc, not just `{{ORDER_ID}}`.

### Placeholder map (suggested)
| Placeholder               | Source field                              |
|---------------------------|-------------------------------------------|
| `{{ORDER_ID}}`            | Order ID                                  |
| `{{ORDER_DATE}}`          | Order date                                |
| `{{ORDER_TOTAL}}`         | Order total (formatted with currency)     |
| `{{PAYMENT_METHOD}}`      | Payment method title                      |
| `{{TRANSACTION_ID}}`      | Transaction ID                            |
| `{{CUSTOMER_NAME}}`       | Billing first + last name                 |
| `{{CUSTOMER_EMAIL}}`      | Billing email                             |
| `{{CUSTOMER_PHONE}}`      | Billing phone                             |
| `{{BILLING_ADDRESS}}`     | Full billing address (multiline)          |
| `{{SHIPPING_ADDRESS}}`    | Full shipping address (multiline)         |
| `{{LINE_ITEMS}}`          | Product names, quantities, subtotals      |
| `{{CHARGEBACK_DATE}}`     | Today's date                              |

### Changes
- Update `duplicateAndFill()` to accept a key-value map and issue multiple `replaceAllText` requests in a single `batchUpdate`.
- Update `POST /api/chargebacks` to auto-fetch the WooCommerce order and build the replacement map.

**Deliverable:** Generated docs are fully populated with real order data.

---

## Step 3 — Order auto-fill in the New Chargeback modal

**What:** After the user enters an Order ID, auto-fetch and preview order data before generating.

### Frontend changes
- Add a "Lookup" button or debounced fetch next to Order ID input.
- Show a preview card with: customer name, email, order total, date, status.
- If the order is not found, display an error message.
- Title auto-populates to `Chargeback #<orderId> — <customerName>`.

**Deliverable:** Admin sees order details before confirming generation.

---

## Step 4 — Chargeback statuses & workflow

**What:** Add meaningful status transitions beyond the initial `generated`.

### Statuses
| Status      | Meaning                                    |
|-------------|--------------------------------------------|
| `generated` | Document created, not yet submitted         |
| `submitted` | Chargeback submitted to payment processor   |
| `won`       | Chargeback resolved in merchant's favor     |
| `lost`      | Chargeback resolved in customer's favor     |

### Changes
- New API endpoint: `PATCH /api/chargebacks/:id/status` (body: `{ status }`).
- Frontend: status badge colors per state + dropdown to change status on the detail page.
- Optional: notes/comments field per status change for audit trail.

**Deliverable:** Admins can track chargeback lifecycle.

---

## Step 5 — Search, filter & pagination

**What:** Make the chargebacks list usable at scale.

### Features
- Server-side pagination (`?page=1&limit=20`).
- Search by title or order ID (`?search=...`).
- Filter by status (`?status=won`).
- Filter by date range (`?from=...&to=...`).
- Sort by any column (`?sort=created_at&order=desc`).

### Frontend
- PrimeVue DataTable with lazy loading, built-in sort headers.
- Search input + status filter dropdown in the toolbar.

**Deliverable:** List performs well with hundreds/thousands of records.

---

## Step 6 — User management

**What:** Allow admins to create/manage other admin users.

### API endpoints

| Method | Path                | Description           |
|--------|---------------------|-----------------------|
| GET    | `/api/users`        | List all users        |
| POST   | `/api/users`        | Create user           |
| PATCH  | `/api/users/:id`    | Update user           |
| DELETE | `/api/users/:id`    | Deactivate user       |

### Frontend
- New "Users" page accessible from a sidebar/nav.
- Table of users with create/edit/delete actions.
- Role selector (admin / viewer if roles expand later).

**Deliverable:** Admin can onboard new team members without touching the DB.

---

## Step 7 — Navigation & layout improvements

**What:** Replace the bare topbar with a proper layout shell.

### Changes
- Sidebar navigation: Chargebacks, Users, Settings.
- Settings page: Google connection status, WooCommerce connection test, folder IDs.
- Responsive layout (collapsible sidebar on mobile).
- Breadcrumbs on detail pages.

**Deliverable:** Dashboard feels like a real internal tool.

---

## Step 8 — Audit log

**What:** Track who did what and when.

### Database
```sql
CREATE TABLE audit_log (
  id         SERIAL PRIMARY KEY,
  user_id    INT REFERENCES users(id),
  action     VARCHAR(100) NOT NULL,
  entity     VARCHAR(100),
  entity_id  INT,
  details    JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

### Logged events
- Chargeback generated / removed / status changed.
- User created / updated / deactivated.
- Google OAuth connected.

### Frontend
- Activity feed on the dashboard or a dedicated "Activity" page.

**Deliverable:** Full traceability for all actions.

---

## Step 9 — Error handling & resilience

**What:** Harden the system for production use.

### Changes
- Global Fastify error handler with structured error responses.
- Google API retry logic (exponential backoff on rate limits).
- WooCommerce API timeout + retry.
- Frontend: global error interceptor, offline indicator, retry buttons.
- Input validation with a schema library (e.g. Zod or Typebox).

**Deliverable:** System handles failures gracefully instead of crashing.

---

## Summary of implementation order

| # | Task | Depends on |
|---|------|------------|
| 1 | WooCommerce API integration | — |
| 2 | Multi-placeholder replacement | Step 1 |
| 3 | Order auto-fill in modal | Steps 1, 2 |
| 4 | Chargeback statuses & workflow | — |
| 5 | Search, filter & pagination | — |
| 6 | User management | — |
| 7 | Navigation & layout | — |
| 8 | Audit log | — |
| 9 | Error handling & resilience | — |

Steps 1-3 are sequential (WooCommerce chain). Steps 4-9 are independent and can be built in any order.

---

## New environment variables

```env
# WooCommerce
WOOCOMMERCE_URL=https://your-store.com
WOOCOMMERCE_CONSUMER_KEY=ck_...
WOOCOMMERCE_CONSUMER_SECRET=cs_...
```
