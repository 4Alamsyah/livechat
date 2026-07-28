# LiveChat Application

A modern real-time chat application built with Laravel 12, Inertia.js v2, Vue 3, and Tailwind CSS. It is an embeddable live-chat widget system: agents work from the internal dashboard in this app, while visitors chat through a small JavaScript widget embedded on any external website.

## Embedding the Widget on Another Site

The live chat widget is designed to be embedded on any external website. It's completely self-contained and requires no installation on the embedding site.

### Quick Start

Add one script tag to any page where you want the chat bubble to appear:

```html
<script src="https://your-livechat-domain.com/js/widget.js" data-property-id="your-site-id"></script>
```

- `https://your-livechat-domain.com` — Replace with your actual live chat server URL
- `data-property-id="your-site-id"` — Unique identifier for this website/property (shown to agents in the dashboard)

**Example:**
```html
<script src="https://livechat.bmt-system2.com/js/widget.js" data-property-id="acme-inc"></script>
```

### Features

- ✅ **Self-contained** — Injects its own styles, no external CSS needed
- ✅ **Real-time chat** — WebSocket connection for instant messaging
- ✅ **Video/Audio calls** — Peer-to-peer communication via PeerJS
- ✅ **Image sharing** — Visitors can upload and share images
- ✅ **Screen sharing** — Agent and visitor can share screen with audio
- ✅ **Notifications** — Toast and browser notifications for new messages
- ✅ **No dependencies** — All libraries loaded from CDN (Pusher-js, PeerJS)
- ✅ **CORS enabled** — Works cross-origin (third-party site embedding)

### Local Testing

Try the demo locally:

```bash
# Start the app
composer run dev

# Open in browser
http://localhost:8000/demo/
```

---

## Installation Guide: Embedding in Your Website

### Option 1: Plain HTML

Add the script tag before closing `</body>` tag:

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Website</title>
</head>
<body>
    <h1>Welcome to my website</h1>
    <p>Our live chat widget appears in the bottom right corner.</p>

    <!-- Add live chat widget -->
    <script src="https://livechat.bmt-system2.com/js/widget.js" data-property-id="my-website"></script>
</body>
</html>
```

**That's it!** The chat bubble will appear in the bottom-right corner of your page.

---

### Option 2: Vue.js Application

#### Step 1: Create a Widget Component

Create file `resources/js/components/LiveChatWidget.vue`:

```vue
<script setup lang="ts">
import { onMounted } from 'vue'

interface Props {
  propertyId?: string
}

withDefaults(defineProps<Props>(), {
  propertyId: 'default-site',
})

onMounted(() => {
  // Prevent double-loading
  if (window.__liveSupportWidgetLoaded) {
    return
  }

  // Create and inject script
  const script = document.createElement('script')
  script.src = 'https://livechat.bmt-system2.com/js/widget.js'
  script.setAttribute('data-property-id', propertyId)
  script.async = true

  script.onerror = () => {
    console.error('[LiveChatWidget] Failed to load widget')
  }

  document.body.appendChild(script)
})
</script>

<template>
  <!-- Component renders nothing; widget loads via script -->
</template>
```

#### Step 2: Add to Your App Layout (Global)

If you want the widget on **all pages**, add to your main layout:

```vue
<!-- app.vue or layouts/AppLayout.vue -->
<script setup>
import LiveChatWidget from '@/components/LiveChatWidget.vue'
</script>

<template>
  <div class="app">
    <!-- Your navbar, sidebar, etc -->
    <header>...</header>
    <main>
      <slot />
    </main>
    
    <!-- Add widget globally -->
    <LiveChatWidget property-id="my-vue-app" />
  </div>
</template>
```

#### Step 3 (Optional): Use Only on Specific Pages

If you want the widget on **specific pages only**:

```vue
<!-- pages/Contact.vue or pages/Support.vue -->
<script setup>
import LiveChatWidget from '@/components/LiveChatWidget.vue'
</script>

<template>
  <div>
    <h1>Contact Us</h1>
    <p>Chat with our team below:</p>
    
    <!-- Widget only on this page -->
    <LiveChatWidget property-id="my-vue-app" />
  </div>
</template>
```

#### Step 4 (Optional): Dynamic Property ID

Use different property IDs for different tenants/customers:

```vue
<script setup>
import LiveChatWidget from '@/components/LiveChatWidget.vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const propertyId = route.params.customerId || 'default'
</script>

<template>
  <div>
    <LiveChatWidget :property-id="propertyId" />
  </div>
</template>
```

---

### Configuration

#### Custom Property ID

Each website/property should have a unique `data-property-id`:

```html
<!-- Website 1 -->
<script src="https://livechat.bmt-system2.com/js/widget.js" data-property-id="acme-inc"></script>

<!-- Website 2 -->
<script src="https://livechat.bmt-system2.com/js/widget.js" data-property-id="grosir-fashion"></script>

<!-- Website 3 -->
<script src="https://livechat.bmt-system2.com/js/widget.js" data-property-id="startup-xyz"></script>
```

In the **agent dashboard**, agents will see conversations grouped by property ID.

#### CDN Resources

The widget automatically loads these from CDN:
- **Pusher-js** (real-time messaging)
- **PeerJS** (video/audio/screen sharing)

No additional setup needed on your site.

---

### CORS & Security

Cross-origin requests from your website to the live chat server are allowed via `config/cors.php`:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_origins' => ['*'],  // Allow requests from any origin
```

This means:
- ✅ Websites can embed the widget and communicate with the chat API
- ✅ No CORS errors
- ✅ No extra headers or configuration needed

---

### Troubleshooting

#### Widget Not Appearing?

1. **Check the URL** — Verify `https://livechat.bmt-system2.com/js/widget.js` is correct
2. **Check property ID** — Make sure `data-property-id="..."` is set
3. **Check browser console** — Open DevTools (F12) → Console tab for errors
4. **Check network tab** — Verify the script is loading (network tab should show a 200 response)

#### Widget Loading But Chat Not Working?

1. **Check WebSocket connection** — Open DevTools → Network tab → search for "websocket"
2. **Check server is running** — Ensure live chat server is running (`composer run dev`)
3. **Check Reverb** — WebSocket server must be running (`php artisan reverb:start`)

#### CORS Error?

If you see `Access to XMLHttpRequest blocked by CORS policy`, make sure:

```php
// config/cors.php
'allowed_origins' => ['*'],  // This allows all origins
'paths' => ['api/*'],
```

---

### Testing Locally

Run this to test locally before production:

```bash
# Terminal 1: Start Laravel + Reverb + Vite
composer run dev

# Terminal 2 (Optional): Start a simple HTTP server for demo
# Serve public/demo/index.html on port 9000
php -S localhost:9000 -t public/demo
```

Then visit:
- **Live chat dashboard:** `http://localhost:8000/agent/dashboard` (login as agent)
- **Demo page with widget:** `http://localhost:9000/` (visitor side)

## Requirements

- **PHP** 8.2 or higher
- **Node.js** 18+ and npm
- **PostgreSQL** 12+ database
- **Composer** for PHP dependency management

## Installation

### 1. Clone and Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install
```

### 2. Environment Setup

Copy `.env.example` to `.env` (if not already created):

```bash
cp .env.example .env
```

Update `.env` with your PostgreSQL database credentials:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=livechat
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

Generate application key:

```bash
php artisan key:generate
```

### 3. Database Setup

Create the database (using PostgreSQL client tools):

```bash
createdb livechat
```

Run migrations:

```bash
php artisan migrate
```

(Optional) Seed database with sample data:

```bash
php artisan db:seed
```

## Running the Application

### Development Mode

Run all services concurrently (recommended):

```bash
composer run dev
```

This will start:
- Laravel development server (port 8000)
- Queue listener
- Reverb WebSocket server
- Vite dev server

Access the app at: `http://localhost:8000`

### Manual Service Startup

If you prefer to run services separately:

**Terminal 1 - Laravel Server:**
```bash
php artisan serve
```

**Terminal 2 - Frontend Dev Server:**
```bash
npm run dev
```

**Terminal 3 - Queue Listener:**
```bash
php artisan queue:listen --tries=1
```

**Terminal 4 - WebSocket Server (Reverb):**
```bash
php artisan reverb:start
```

## Building for Production

### Build Frontend Assets

```bash
npm run build
```

This compiles Vue components and Tailwind CSS for production.

### Deployment

For production deployment, refer to [Laravel Deployment Guide](https://laravel.com/docs/deployment) and [Laravel Cloud](https://cloud.laravel.com/).

## Testing

Run all tests:

```bash
php artisan test
```

Run tests with compact output:

```bash
php artisan test --compact
```

Run specific test file:

```bash
php artisan test --compact --filter=FeatureTest
```

## Code Quality

### Format Code with Pint

```bash
vendor/bin/pint
```

### Format Frontend Code

```bash
npm run format
```

### Check Frontend Code Style

```bash
npm run format:check
```

### Lint JavaScript/Vue

```bash
npm run lint
```

## Project Structure

```
├── app/                    # Application PHP code
│   ├── Http/              # Controllers, middleware
│   ├── Models/            # Eloquent models
│   └── ...
├── bootstrap/             # Application bootstrap
│   ├── app.php           # Application configuration
│   └── providers.php     # Service providers
├── config/               # Configuration files
├── database/             # Migrations, factories, seeders
├── public/               # Public assets (compiled)
├── resources/
│   ├── js/               # Vue components and pages
│   │   ├── components/   # Reusable Vue components
│   │   ├── pages/        # Inertia page components
│   │   └── layouts/      # Layout components
│   └── views/            # Blade views (if any)
├── routes/               # API and web routes
├── storage/              # Application storage (logs, cache)
├── tests/                # Pest tests
├── .env                  # Environment configuration
├── package.json          # Node dependencies
└── composer.json         # PHP dependencies
```

## Key Technologies

- **Backend:** Laravel 12, PHP 8.2
- **Frontend:** Vue 3, Inertia.js v2, Tailwind CSS 3
- **Real-time:** Laravel Reverb (WebSocket)
- **Testing:** Pest 3, PHPUnit 11
- **Database:** PostgreSQL
- **Build Tools:** Vite, npm
- **Code Quality:** Laravel Pint, ESLint, Prettier

## Troubleshooting

### Frontend Changes Not Reflecting

If you make changes to Vue components but don't see them in the browser:

```bash
# Kill current dev server and rebuild
npm run build
```

or run the full dev server:

```bash
composer run dev
```

### Database Connection Error

Ensure PostgreSQL is running and database exists:

```bash
# Create database if not exists
createdb livechat

# Run migrations
php artisan migrate
```

### Port Already in Use

If port 8000 is already in use, start server on different port:

```bash
php artisan serve --port=8001
```

### Clear Application Cache

```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

## Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Inertia.js Documentation](https://inertiajs.com)
- [Vue 3 Documentation](https://vuejs.org)
- [Tailwind CSS Documentation](https://tailwindcss.com)
- [Laravel Reverb Documentation](https://laravel.com/docs/reverb)

## License

This project is open-sourced software licensed under the MIT license.
