FROM php:8.2-apache

# PHP extensions used by the app (mysqli for the DB, gd for PDF image handling)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install mysqli gd \
    && a2enmod rewrite headers remoteip \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

RUN { \
      echo 'date.timezone=Asia/Manila'; \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=25M'; \
      echo 'memory_limit=256M'; \
      echo 'expose_php=Off'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
    } > /usr/local/etc/php/conf.d/app.ini

# Railway's edge proxy forwards the real client IP. Without this, REMOTE_ADDR is the
# proxy's internal address, which changes between requests and breaks the OTP IP check.
RUN { \
      echo 'RemoteIPHeader X-Real-IP'; \
      echo 'RemoteIPTrustedProxy 0.0.0.0/1 128.0.0.0/1 ::/1 8000::/1'; \
      echo 'ServerName localhost'; \
    } > /etc/apache2/conf-available/railway-proxy.conf \
    && a2enconf railway-proxy

WORKDIR /var/www/html
COPY . /var/www/html/

# Keep a pristine copy of uploads/ so the entrypoint can seed a mounted volume
RUN cp -a uploads /opt/uploads-seed \
    && chown -R www-data:www-data /var/www/html/uploads

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

ENV PORT=8080
CMD ["/usr/local/bin/docker-entrypoint.sh"]
