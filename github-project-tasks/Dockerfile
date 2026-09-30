FROM ghcr.io/astral-sh/uv:python3.13-alpine

WORKDIR /app

COPY app.py /app/app.py

RUN apk add --no-cache tzdata \
    && mkdir -p /app/state \
    && chown 1000:1000 /app/state

ENV UV_CACHE_DIR=/tmp/uv-cache

USER 1000:1000

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/health

CMD ["uv", "run", "--no-project", "--no-managed-python", "/app/app.py"]
