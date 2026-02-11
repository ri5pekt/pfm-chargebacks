# PFM Chargebacks Automation

A comprehensive chargeback management system built with Vue.js, Fastify, PostgreSQL, and WooCommerce integration.

## Features

- 🎯 **Template-based Document Generation** - Create chargeback documents from Google Docs templates
- 🔄 **WooCommerce Integration** - Auto-fill order data from WooCommerce
- 📸 **Screenshot Support** - Upload screenshots with drag & drop, browse, or paste (Ctrl+V)
- 🗺️ **Smart Field Mapping** - Map template placeholders to WooCommerce fields
- 👥 **User Management** - Role-based access control (admin/user)
- 🔐 **Secure Authentication** - JWT-based authentication system
- 📊 **Order Tracking** - Track all created chargebacks with status
- ☁️ **Google Drive Integration** - Templates and generated documents stored in Google Drive

## Tech Stack

### Frontend
- **Vue.js 3** - Progressive JavaScript framework
- **TypeScript** - Type-safe development
- **PrimeVue** - UI component library
- **Pinia** - State management
- **Vite** - Fast build tool

### Backend
- **Fastify** - Fast Node.js web framework
- **PostgreSQL** - Reliable database
- **Google APIs** - Drive and Docs integration
- **Braintree PHP SDK** - Payment dispute data retrieval
- **WooCommerce REST API** - Order data integration

### Infrastructure
- **Docker** - Containerized deployment
- **Docker Compose** - Multi-container orchestration

## Prerequisites

- Docker and Docker Compose
- Google Cloud Project with Drive & Docs API enabled
- WooCommerce store with custom plugin installed
- Node.js 20+ (for local development)

## Quick Start

1. **Clone the repository**
   ```bash
   git clone https://github.com/ri5pekt/pfm-chargebacks.git
   cd pfm-chargebacks
   ```

2. **Set up environment variables**
   ```bash
   cp .env.example .env
   # Edit .env with your credentials
   ```

3. **Start the application**
   ```bash
   docker compose up -d
   ```

4. **Access the application**
   - Frontend: http://localhost:5174
   - Backend: http://localhost:3000
   - Default credentials: Check the seed file

## Environment Variables

Create a `.env` file in the root directory:

```env
# Database
POSTGRES_USER=postgres
POSTGRES_PASSWORD=postgres
POSTGRES_DB=chargebacks

# JWT Secret
JWT_SECRET=your-secret-key-here

# Google OAuth
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=http://localhost:3000/api/oauth/google/callback

# Google Drive Folders
GOOGLE_TEMPLATES_FOLDER_ID=your-templates-folder-id
GOOGLE_GENERATED_FOLDER_ID=your-generated-docs-folder-id

# WooCommerce
WOO_BASE=https://your-store.com
WOO_TOKEN=your-woocommerce-api-token
```

## Project Structure

```
pfm-chargebacks-automation/
├── frontend/                 # Vue.js frontend application
│   ├── src/
│   │   ├── components/      # Reusable components
│   │   ├── views/           # Page components
│   │   ├── stores/          # Pinia stores
│   │   └── main.ts
│   ├── public/              # Static assets (favicon, etc.)
│   └── Dockerfile
├── backend/                  # Fastify backend API
│   ├── src/
│   │   ├── db/              # Database migrations & seed
│   │   ├── routes/          # API endpoints
│   │   ├── services/        # Business logic
│   │   └── plugins/         # Fastify plugins
│   └── Dockerfile
├── woocommerce-plugin/      # WordPress/WooCommerce plugin
│   └── pfm-chargebacks-utils.php
├── docker-compose.yml       # Docker orchestration
└── README.md

## WooCommerce Plugin Installation

1. Copy `woocommerce-plugin/pfm-chargebacks-utils.php` to your WordPress plugins directory
2. Activate the plugin in WordPress admin
3. Configure Braintree credentials in the plugin
4. The plugin exposes a REST API endpoint: `/wp-json/pfm-chargebacks/v1/order/{id}`

## Field Mapping System

### Supported Mapping Types

1. **WooCommerce Getters** - `woo_order_get_*`
   - Maps to WC_Order methods: `get_billing_email()`, `get_total()`, etc.

2. **Order Meta Fields** - `woo_order_meta_*`
   - Access custom order meta with dot notation for nested values
   - Example: `woo_order_meta__braintree_card_details.last4`

3. **Special Handlers** - `special_*`
   - Complex data retrieval with custom logic
   - Examples:
     - `special_products_and_quantities` - Format order items
     - `special_case_id` - Braintree dispute ID
     - `special_disputed_total` - Dispute amount
     - `special_shipping_carrier` - Actual carrier from tracking
     - `special_ppu_product_name` - Post-purchase upsell product

### Screenshot Placeholders

Use `[screenshot_1 Description]` format in templates:
- Automatically detected and filtered from text mappings
- Upload via drag & drop, file browser, or Ctrl+V paste
- Images uploaded to Google Drive and embedded in documents
- Empty placeholders are removed from final document

## Database Schema

### Core Tables
- `users` - User accounts with role-based access
- `chargebacks` - Created chargeback records
- `placeholder_mappings` - Template placeholder to WooCommerce field mappings
- `google_tokens` - OAuth tokens for Google API access

## API Endpoints

### Authentication
- `POST /api/auth/login` - User login
- `POST /api/auth/logout` - User logout
- `GET /api/auth/me` - Get current user

### Templates
- `GET /api/templates` - List Google Drive templates
- `GET /api/templates/:id/placeholders` - Extract placeholders from template

### Chargebacks
- `GET /api/chargebacks` - List all chargebacks
- `POST /api/chargebacks` - Create new chargeback (supports multipart for screenshots)
- `GET /api/chargebacks/:id` - Get chargeback details
- `DELETE /api/chargebacks/:id` - Delete chargeback (admin only)

### WooCommerce
- `GET /api/woocommerce/order/:id` - Get raw order data
- `GET /api/woocommerce/order/:id/mapped` - Get order with mapped fields

### Settings
- `GET /api/settings/mappings` - List field mappings
- `PATCH /api/settings/mappings/:id` - Update mapping
- `POST /api/settings/mappings/sync` - Sync placeholders to database

### Users (Admin only)
- `GET /api/users` - List all users
- `POST /api/users` - Create new user
- `DELETE /api/users/:id` - Delete user
- `PATCH /api/users/me/password` - Change own password

### Google OAuth
- `GET /api/oauth/google/start` - Initiate OAuth flow
- `GET /api/oauth/google/callback` - OAuth callback handler
- `GET /api/oauth/google/status` - Check connection status

## Development

### Frontend Development
```bash
cd frontend
npm install
npm run dev
```

### Backend Development
```bash
cd backend
npm install
npm run dev
```

### Database Migrations
Migrations run automatically on backend startup. Manual execution:
```bash
docker compose exec backend npm run migrate
```

## Production Deployment

1. Update `.env` with production values
2. Build Docker images:
   ```bash
   docker compose build
   ```
3. Deploy with Docker Compose or your preferred orchestration tool
4. Set up HTTPS reverse proxy (nginx, Traefik, etc.)
5. Configure Google OAuth redirect URIs for production domain
6. Set up database backups

## Security Considerations

- ✅ JWT-based authentication with httpOnly cookies
- ✅ Password hashing with bcrypt
- ✅ Role-based access control
- ✅ CORS configuration
- ✅ SQL injection prevention with parameterized queries
- ✅ File upload size limits (10MB)
- ⚠️ Ensure `.env` is never committed to version control
- ⚠️ Use strong JWT_SECRET in production
- ⚠️ Enable HTTPS in production

## Version

Current version: **1.0.0**

## License

Private - All rights reserved

## Support

For issues and questions, please contact the development team.
