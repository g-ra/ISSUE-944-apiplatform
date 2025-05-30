#syntax=docker/dockerfile:1

# Adapted from https://github.com/dunglas/symfony-docker

# Cache
FROM dunglas/frankenphp:1-builder-php8.3 AS builder
COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy


# Set environment variables for PIE
RUN apt-get update && apt-get install --no-install-recommends -y \
    git

ENV CGO_ENABLED=1 XCADDY_SETCAP=1 XCADDY_GO_BUILD_FLAGS="-ldflags \"-w -s -extldflags '-Wl,-z,stack-size=0x80000'\""
ENV CGO_CFLAGS="-fPIC" \
    CGO_LDFLAGS="-pie" \
    GOFLAGS="-buildmode=pie"

RUN xcaddy build \
    --output /usr/local/bin/frankenphp \
    --with github.com/dunglas/frankenphp/caddy \
    --with github.com/dunglas/mercure/caddy \
    --with github.com/dunglas/vulcain/caddy \
    --with github.com/dunglas/caddy-cbrotli \
    # You should use another storage than the default one (e.g. otter).
    # The list of the available storages can be find either on the documentation website (https://docs.souin.io/docs/storages/) or on the storages repository https://github.com/darkweak/storages
	#    --with github.com/caddyserver/cache-handler
    # Or use the following lines instead of the cache-handler one for the latest improvements
    #--with github.com/darkweak/souin/plugins/caddy \
    #--with github.com/darkweak/storages/otter/caddy
	--with github.com/darkweak/souin/plugins/caddy@36d876ffb2b82cb42a7b45d094f9a41f0692017d \
	--with github.com/darkweak/souin@36d876ffb2b82cb42a7b45d094f9a41f0692017d \
    --with github.com/darkweak/storages/redis/caddy
# Versions
FROM dunglas/frankenphp:1-php8.3 AS frankenphp_upstream

# Cache
COPY --from=builder --link /usr/local/bin/frankenphp /usr/local/bin/frankenphp


# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/develop/develop-images/multistage-build/#stop-at-a-specific-build-stage
# https://docs.docker.com/compose/compose-file/#target


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

#RUN set -eux; \
#    apt-get update; \
#    apt-get install -y --no-install-recommends gnupg wget ca-certificates lsb-release; \
#    wget -qO - https://www.mongodb.org/static/pgp/server-8.0.asc | gpg --dearmor -o /usr/share/keyrings/mongodb-archive-keyring.gpg; \
#    echo "deb [signed-by=/usr/share/keyrings/mongodb-archive-keyring.gpg] http://repo.mongodb.org/apt/debian $(lsb_release -cs)/mongodb-org/8.0 main" | tee /etc/apt/sources.list.d/mongodb-org-8.0.list;

# persistent / runtime deps
# hadolint ignore=DL3008
RUN apt-get update && apt-get install --no-install-recommends -y \
    acl \
    file \
    gettext \
    git \
    libreoffice \
    supervisor \
    cron \
    build-essential \
    autoconf \
    libc6-dev \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN set -eux; \
    install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        zip \
        mongodb \
        gd \
        bcmath \
        amqp \
        sockets \
        imagick/imagick@master \
        imap \
        redis \
        pcntl \
    ;

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN set -eux; \
    install-php-extensions pdo_pgsql
###< doctrine/doctrine-bundle ###
###< recipes ###

#

COPY --link frankenphp/conf.d/app.ini $PHP_INI_DIR/conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/caddy/Caddyfile

#SENTRY
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install excimer; \
    docker-php-ext-enable excimer; \
    apt-get remove -y $PHPIZE_DEPS; \
    apt-get autoremove -y; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*
#

# Supervisor
ARG SUPERVISOR_ENABLED=false
ENV SUPERVISOR_ENABLED=$SUPERVISOR_ENABLED
RUN if [ "$SUPERVISOR_ENABLED" = 'true' ]; then \
        apt-get update && apt-get install -y supervisor && apt-get clean; \
    fi
RUN if [ "$SUPERVISOR_ENABLED" = 'true' ]; then \
        chmod +x /usr/bin/supervisord; \
    fi


RUN touch /var/log/cron.log

COPY . /app

# Run the cron
ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ARG XDEBUG_MODE=off
ENV APP_ENV=dev XDEBUG_MODE=$XDEBUG_MODE PHP_IDE_CONFIG=serverName=xdebug-crm
VOLUME /app/var/
# ENV FRANKENPHP_CONFIG="import worker.dev.Caddyfile"
#ENV FRANKENPHP_CONFIG="worker.enabled=0"

COPY --link frankenphp/worker.dev.Caddyfile /etc/caddy/worker.Caddyfile
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN if [ "$XDEBUG_MODE" = "debug" ]; then \
    apt-get update && apt-get install -y \
        gcc \
        make \
        autoconf \
        wget \
        && apt-get clean; \
    set -eux; \
        install-php-extensions \
            xdebug \
        ; \
    fi

COPY --link frankenphp/conf.d/app.dev.ini $PHP_INI_DIR/conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--watch" ]

# Test FrankenPHP image
FROM frankenphp_dev AS frankenphp_test
ENV APP_ENV=test

# Prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
#ENV FRANKENPHP_CONFIG="import worker.dev.Caddyfile"
#ENV FRANKENPHP_CONFIG="worker.enabled=0"

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/
COPY --link frankenphp/worker.prod.Caddyfile /etc/caddy/worker.Caddyfile

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN set -eux; \
    composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link . ./
RUN rm -Rf frankenphp/

RUN set -eux; \
    mkdir -p var/cache var/log; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd; \
    chmod +x bin/console; sync;
