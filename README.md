# 1. Clone the repo
git clone https://github.com/yourusername/laravel-country-exchange-api.git
cd laravel-country-exchange-api

# 2. Install dependencies
composer install
npm install && npm run build

# 3. Copy .env
cp .env.example .env

# 4. Generate app key
php artisan key:generate

# 5. Setup Database in .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stage2
DB_USERNAME=root
DB_PASSWORD=

# 6. Run migrations
php artisan migrate

# 7. Serve the app
php artisan serve
