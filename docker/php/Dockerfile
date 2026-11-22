# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22
ARG COMPOSER_VERSION=2.8
ARG CASTOR_VERSION=0.27.0
ARG FRANKENPHP_VERSION=1.13.4

# ---------------------------------------------------------------------------
# Stage 0 - upstream images (declared as stages so ARGs can be interpolated
# in later COPY --from= directives).
# ---------------------------------------------------------------------------
FROM composer:${COMPOSER_VERSION} AS composer_upstream

# ---------------------------------------------------------------------------
# Build context: the REPOSITORY ROOT (see .dockerignore). Every COPY below is
# relative to it.
#
# Stage 1 - frankenphp_base
# Runtime image under the dev stage. Shared by the three PHP apps
# (shop, mcp-server, chat): same PHP, same extensions; they differ by
# working_dir and Caddyfile only (see compose.yaml).
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm AS frankenphp_base

ARG USER=app
ARG USER_ID=1000
ARG GROUP_ID=1000

ENV SERVER_NAME=:80
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

# pcntl: a Messenger consumer (shop-worker, the MCP server's consumers) finishes
# the message in flight on SIGTERM only with it; without it, a stop kills it at once.
RUN install-php-extensions \
        opcache \
        intl \
        pdo_mysql \
        exif \
        gd \
        imagick \
        zip \
        sysvsem \
        uuid \
        pcntl

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        bash \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid ${GROUP_ID} ${USER} \
    && useradd --uid ${USER_ID} --gid ${GROUP_ID} --create-home --shell /bin/bash ${USER} \
    && mkdir -p /data/caddy /config/caddy /srv/app \
    && chown -R ${USER}:${USER} /data/caddy /config/caddy /srv/app

COPY docker/php/conf.d/app.ini /usr/local/etc/php/app.conf.d/app.ini
# No default Caddyfile: each app ships its own (apps/<app>/docker/Caddyfile)
# and is started with `--config` by compose.

WORKDIR /srv/app

# ---------------------------------------------------------------------------
# Stage 2 - frankenphp_dev
# Dev stage: Composer, Node/Yarn, Castor, Xdebug, debug aliases. The code is
# bind-mounted by compose.dev.yaml.
# ---------------------------------------------------------------------------
FROM frankenphp_base AS frankenphp_dev

ARG USER
ARG NODE_VERSION
ARG COMPOSER_VERSION
ARG CASTOR_VERSION

ENV XDEBUG_MODE=off

RUN install-php-extensions xdebug

# Composer (from upstream stage declared at top of file)
COPY --from=composer_upstream /usr/bin/composer /usr/local/bin/composer

# Node + Yarn (the shop builds its assets with them; sylius-standard 2.3 wants ^22.13 || >=24)
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g yarn \
    && rm -rf /var/lib/apt/lists/*

# Castor (Emagma task runner)
RUN curl -fsSL "https://github.com/jolicode/castor/releases/download/v${CASTOR_VERSION}/castor.linux-amd64.phar" \
        -o /usr/local/bin/castor \
    && chmod +x /usr/local/bin/castor

# Dev-only PHP overrides (display errors, xdebug client)
COPY docker/php/conf.d/app.dev.ini /usr/local/etc/php/app.conf.d/zz-app.dev.ini

# Bash niceties for developer ergonomics
RUN echo "alias console='bin/console'" >> /home/${USER}/.bashrc \
    && echo "alias sf='bin/console'" >> /home/${USER}/.bashrc \
    && echo "alias pu='vendor/bin/phpunit'" >> /home/${USER}/.bashrc \
    && chown ${USER}:${USER} /home/${USER}/.bashrc

USER ${USER}
