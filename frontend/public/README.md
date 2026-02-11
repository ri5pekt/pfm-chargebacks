# Public Assets Folder

This folder contains static assets that will be served at the root URL in both development and production.

## Favicon

Place your favicon files here:
- `favicon.svg` - Vector favicon (recommended, scales perfectly)
- `favicon.png` - PNG fallback (16x16, 32x32, or 48x48)
- `favicon.ico` - Classic ICO format (optional)

The favicon will be automatically available at:
- Dev: `http://localhost:5173/favicon.svg`
- Prod: `https://yourdomain.com/favicon.svg`

## How to Update Favicon

1. Replace `favicon.svg` or `favicon.png` with your custom icon
2. The file is already referenced in `index.html`
3. Restart dev server to see changes
4. For production, rebuild with `npm run build`

## Current Favicon

The current favicon matches the app logo:
- Orange gradient background (#fb923c → #f97316)
- White credit card icon
- Rounded corners for modern look
