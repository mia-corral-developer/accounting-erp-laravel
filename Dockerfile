# Supported PHP versions: 8.2, 8.3, 8.5
ARG PHP_VERSION=8.5
# PHP 8.5 support requires compatible extension builds (check install-php-extensions)

###########################################
# Composer dependencies stage
###########################################
FROM php:${PHP_VERSION}-cli-alpine AS composer-deps

WORKDIR /app

# Install required extensions for composer install
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl sockets zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy composer files
COPY composer.json composer.lock ./

# Local Liberu modules are Composer path repositories and must be present before
# dependency installation. The application stage copies the full source later.
COPY modules ./modules

# Install composer dependencies (no autoloader yet, will optimize in final stage)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-ansi \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-req=ext-pcntl

###########################################
# Frontend assets stage
###########################################
# The application layouts call @vite(...), which requires public/build/manifest.json.
# Without a build step the manifest is missing and every web route returns 500
# ("Vite manifest not found"). Compile the assets here and copy them into the
# runtime image.
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN npm run build

###########################################
# Main application stage (PHP-FPM)
###########################################
FROM php:${PHP_VERSION}-fpm-alpine AS app

LABEL maintainer="SMortexa <seyed.me720@gmail.com>"
LABEL org.opencontainers.image.title="Laravel Octane Dockerfile"
LABEL org.opencontainers.image.description="Production-ready Dockerfile for Laravel Octane"
LABEL org.opencontainers.image.source=https://github.com/exaco/laravel-octane-dockerfile
LABEL org.opencontainers.image.licenses=MIT

ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG TZ=UTC

ENV TERM=xterm-color \
    WITH_HORIZON=false \
    WITH_SCHEDULER=false \
    WITH_REVERB=false \
    OCTANE_SERVER=roadrunner \
    USER=octane \
    ROOT=/var/www/html \
    COMPOSER_FUND=0 \
    COMPOSER_MAX_PARALLEL_HTTP=24

WORKDIR ${ROOT}

SHELL ["/bin/sh", "-lc"]

RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
  && echo ${TZ} > /etc/timezone

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install system dependencies and PHP extensions in one layer
RUN apk update && \
    apk upgrade && \
    apk add --no-cache \
    curl \
    wget \
    nano \
    ncdu \
    procps \
    ca-certificates \
    supervisor \
    libsodium-dev \
    su-exec && \
    install-php-extensions \
    bz2 \
    pcntl \
    mbstring \
    bcmath \
    sockets \
    pgsql \
    pdo_pgsql \
    opcache \
    exif \
    pdo_mysql \
    zip \
    intl \
    gd \
    redis \
    pcntl \
    igbinary && \
    docker-php-source delete && \
    rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

RUN arch="$(apk --print-arch)" \
    && case "$arch" in \
    armhf) _cronic_fname='supercronic-linux-arm' ;; \
    aarch64) _cronic_fname='supercronic-linux-arm64' ;; \
    x86_64) _cronic_fname='supercronic-linux-amd64' ;; \
    x86) _cronic_fname='supercronic-linux-386' ;; \
    *) echo >&2 "error: unsupported architecture: $arch"; exit 1 ;; \
    esac \
    && wget -q "https://github.com/aptible/supercronic/releases/download/v0.2.29/${_cronic_fname}" \
    -O /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php ${ROOT}/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

RUN addgroup -g ${WWWGROUP} ${USER} \
    && adduser -D -h ${ROOT} -G ${USER} -u ${WWWUSER} -s /bin/sh ${USER}

RUN mkdir -p /var/log/supervisor /var/run/supervisor \
    && chown -R ${USER}:${USER} ${ROOT} /var/log /var/run \
    && chmod -R a+rw ${ROOT} /var/log /var/run

RUN cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini

# NOTE: the image intentionally stays root. php-fpm's master process must start
# as root so it can setuid its workers to the unprivileged "${USER}". (The old
# Octane image dropped privileges here, but FPM cannot drop and then fork.)

# Install Composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy vendor from composer-deps stage for better caching
COPY --chown=${USER}:${USER} --from=composer-deps /app/vendor ./vendor

# Copy composer files (needed for autoloader generation)
COPY --chown=${USER}:${USER} composer.json composer.lock ./

# Copy application code first so autoloader can resolve all files
COPY --chown=${USER}:${USER} . .

# Bring in the compiled frontend assets (public/build/manifest.json + bundles)
COPY --chown=${USER}:${USER} --from=frontend /app/public/build ./public/build

# Generate optimized autoloader now that all app files are present
RUN composer dump-autoload --classmap-authoritative --no-dev && \
    composer clear-cache

# Create necessary Laravel directories
RUN mkdir -p \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache && \
    chmod -R a+rw storage

# Copy configuration files
# PHP-FPM runtime: remove the stock "zz-docker.conf" (it defines its own [www]
# pool that collides with ours) and install our pool + php.ini overrides.
RUN rm -f /usr/local/etc/php-fpm.d/zz-docker.conf
COPY .docker/fpm/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY .docker/php.ini ${PHP_INI_DIR}/conf.d/99-app.ini
COPY .docker/start-container /usr/local/bin/start-container

# Copy environment file
COPY --chown=${USER}:${USER} .env.example ./.env

RUN chmod +x /usr/local/bin/start-container && \
    cat .docker/utilities.sh >> ~/.bashrc

EXPOSE 9000

ENTRYPOINT ["start-container"]

HEALTHCHECK --start-period=10s --interval=5s --timeout=5s --retries=6 CMD php -r '$c=@fsockopen("127.0.0.1",9000); exit($c?0:1);'

###########################################
# Web server stage (nginx)
###########################################
# Serves public/ as static files and proxies PHP to the "app" (php-fpm) service.
# Separate container = one process each, no supervisord orchestration.
FROM nginx:alpine AS web

COPY --from=app /var/www/html/public /var/www/html/public
COPY .docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
