# Gunakan mesin PHP 8.4 (menyesuaikan dengan versi laptopmu)
FROM php:8.4-cli

# Install alat bantu yang dibutuhkan Laravel & Driver PostgreSQL untuk Supabase
RUN apt-get update -y && apt-get install -y libzip-dev unzip git libpq-dev \
    && docker-php-ext-install zip pdo pdo_pgsql

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set folder kerja
WORKDIR /app

# Copy seluruh kode Laravel ke dalam mesin
COPY . /app

# Install dependency Laravel
RUN composer install --no-dev --optimize-autoloader

# Buka port
ENV PORT=10000
EXPOSE $PORT

# Perintah untuk menyalakan server saat deploy selesai
CMD php artisan serve --host=0.0.0.0 --port=$PORT