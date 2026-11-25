FROM python:3.12-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . /app

ENV PYTHONUNBUFFERED=1 \
    PYTHONPATH=/app \
    ESQUINA_WORK_DIR=/tmp/esquina-video-worker

CMD ["python3", "-m", "esquina_video_worker"]
