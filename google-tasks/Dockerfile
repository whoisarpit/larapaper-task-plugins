FROM alpine:3.22 AS source

WORKDIR /app

COPY public ./public
COPY src ./src

FROM php:8.3-cli-alpine AS runtime

RUN apk add --no-cache libcurl \
    && apk add --no-cache --virtual .build-deps curl-dev \
    && docker-php-ext-install curl \
    && apk del .build-deps

RUN addgroup -S connector \
    && adduser -S -G connector connector \
    && mkdir -p /app/storage \
    && chown -R connector:connector /app

WORKDIR /app

COPY --from=source --chown=connector:connector /app /app

USER connector

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/health || exit 1

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
