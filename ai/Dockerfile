# Civi Pulse AI service - CPU image by default (no GPU required).
#
# GPU later: build with a CUDA wheel index, e.g.
#   docker build --build-arg TORCH_INDEX_URL=https://download.pytorch.org/whl/cu124 -t civi-pulse-ai:gpu .
# and enable the GPU block in docker-compose.yml.
FROM python:3.11-slim

ARG TORCH_INDEX_URL=https://download.pytorch.org/whl/cpu

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    HF_HOME=/app/.cache/huggingface \
    YOLO_CONFIG_DIR=/app/.cache/ultralytics \
    YOLO_OFFLINE=1

# System libraries needed by OpenCV.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
# Install torch from the selected index first so pip does not pull CUDA wheels.
RUN pip install --index-url ${TORCH_INDEX_URL} torch torchvision \
    && pip install --extra-index-url ${TORCH_INDEX_URL} -r requirements.txt

COPY app ./app
COPY scripts ./scripts
COPY training ./training
RUN mkdir -p /app/models /app/datasets /app/.cache \
    && useradd --create-home --uid 1000 appuser \
    && chown -R appuser:appuser /app
USER appuser

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/api/v1/health').status==200 else 1)"

CMD ["sh", "-c", "uvicorn app.main:app --host ${HOST:-0.0.0.0} --port ${PORT:-8000}"]
