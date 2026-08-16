FROM python:3.12-slim AS runtime

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

RUN addgroup --system scraper && adduser --system --ingroup scraper scraper

WORKDIR /app

COPY pyproject.toml requirements.lock ./
COPY src ./src

RUN pip install --no-cache-dir --requirement requirements.lock \
    && pip install --no-cache-dir --no-deps .

USER scraper

ENTRYPOINT ["celery", "-A", "sales_spy_scrapers.tasks:celery_app"]
CMD ["worker", "--loglevel=INFO", "--queues=crawl", "--concurrency=1"]
