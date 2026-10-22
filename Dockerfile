FROM php:8.2-apache

# Extensiones necesarias
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Módulos Apache
RUN a2enmod rewrite headers

# Copiar código
COPY . /var/www/html/

# Permisos correctos
RUN chown -R www-data:www-data /var/www/html

# Render inyecta $PORT dinámicamente
EXPOSE 80
CMD sed -i "s/80/\${PORT:-80}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf \
    && apache2-foreground
