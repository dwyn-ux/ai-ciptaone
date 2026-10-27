FROM python:3.12-slim

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy source code and migrations
COPY migrations /migrations
COPY app /app/app
COPY tests /app/tests

# Create private proof directory
RUN mkdir -p /app/storage/proofs

EXPOSE 8000

ENV PYTHONUNBUFFERED=1
ENV PROOF_UPLOAD_DIR=/app/storage/proofs

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
