FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libonig5 \
    && docker-php-ext-install pdo_mysql mysqli mbstring \
    && a2enmod rewrite headers \
    && echo 'DirectoryIndex front-end/login.html index.php index.html' >> /etc/apache2/apache2.conf \
    && printf 'upload_max_filesize=10M\npost_max_size=35M\n' > /usr/local/etc/php/conf.d/uploads.ini \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p /var/www/html/backend/uploads/camisas \
        /var/www/html/backend/pagamentos/uploads \
        /var/www/html/backend/admin_backend/storage/comprovantes \
    && chown -R www-data:www-data /var/www/html/backend/uploads \
        /var/www/html/backend/pagamentos/uploads \
        /var/www/html/backend/admin_backend/storage

# O Render fornece PORT. A imagem oficial do Apache escuta na porta 80 por padrão.
EXPOSE 10000
CMD ["bash", "-c", "port=\"${PORT:-10000}\"; sed -i \"s/Listen 80/Listen ${port}/\" /etc/apache2/ports.conf; sed -i \"s/:80>/:${port}>/\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]
