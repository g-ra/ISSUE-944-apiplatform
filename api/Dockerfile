#syntax=docker/dockerfile:1.4

# Adapted from https://github.com/dunglas/symfony-docker

# Versions
FROM dunglas/frankenphp:latest-builder AS builder
COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy


# Set environment variables for PIE
ENV CGO_ENABLED=1
ENV XCADDY_SETCAP=1
ENV XCADDY_GO_BUILD_FLAGS="-ldflags \"-w -s -extldflags '-Wl,-z,stack-size=0x80000'\""
ENV   CGO_CFLAGS="-fPIC" \
     CGO_LDFLAGS="-pie" \
     GOFLAGS="-buildmode=pie"
RUN xcaddy build \
    --output /usr/local/bin/frankenphp \
    --with github.com/dunglas/frankenphp \
    --with github.com/dunglas/frankenphp/caddy\
    --with github.com/dunglas/mercure/caddy \
    --with github.com/dunglas/vulcain/caddy \
    --with github.com/caddyserver/cache-handler
FROM dunglas/frankenphp:latest AS frankenphp_upstream

COPY --from=builder --link /usr/local/bin/frankenphp /usr/local/bin/frankenphp

# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base
WORKDIR /app

# persistent / runtime deps
# hadolint ignore=DL3008
RUN apt-get update && apt-get install --no-install-recommends -y \
	acl \
	file \
	gettext \
	git \
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
	;

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN set -eux; \
	install-php-extensions pdo_pgsql
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link frankenphp/conf.d/app.ini $PHP_INI_DIR/conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/caddy/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev XDEBUG_MODE=off
VOLUME /app/var/

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
	install-php-extensions \
		xdebug \
	;

COPY --link frankenphp/conf.d/app.dev.ini $PHP_INI_DIR/conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--watch" ]

# Prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="import worker.Caddyfile"

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/
COPY --link frankenphp/worker.Caddyfile /etc/caddy/worker.Caddyfile

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
