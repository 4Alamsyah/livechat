# LiveChat Application

A modern real-time chat application built with Laravel 12, Inertia.js v2, Vue 3, and Tailwind CSS. It is an embeddable live-chat widget system: agents work from the internal dashboard in this app, while visitors chat through a small JavaScript widget embedded on any external website.

## Embedding the Widget on Another Site

Add one script tag to any page you want the chat bubble to appear on:

```html
<script src="https://your-livechat-domain.com/js/widget.js" data-property-id="your-site-id"></script>
```

- `data-property-id` identifies which site/property the conversation came from (shown to agents in the dashboard). Use a different value per site if you embed on multiple properties.
- The widget is self-contained: it injects its own styles, loads Pusher-js and PeerJS from CDN as needed, and talks to `/api/widget/*` on the domain the script was loaded from.
- Cross-origin requests from the embedding site to the widget API are allowed via `config/cors.php` (`api/*` paths, all origins) — no extra setup needed on the embedding site.
- Try it locally with [public/demo/index.html](public/demo/index.html), which simulates a third-party site embedding the widget.

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
