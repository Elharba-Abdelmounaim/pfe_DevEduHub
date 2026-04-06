# ── DevEduHub Grader Service ───────────────────────────────
FROM python:3.11-slim

# Build argument for host Docker GID
ARG DOCKER_GID

# Install git + Docker CLI (MUST run as root)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl ca-certificates gnupg \
    && curl -fsSL https://download.docker.com/linux/debian/gpg \
       | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) \
       signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] \
       https://download.docker.com/linux/debian bullseye stable" \
       > /etc/apt/sources.list.d/docker.list \
    && apt-get update && apt-get install -y --no-install-recommends docker-ce-cli \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Create docker group with host GID, then create grader user
RUN groupadd -g ${DOCKER_GID} docker 2>/dev/null || true && \
    useradd -m -u 1000 -G docker grader

WORKDIR /app

# Copy dependencies first (better layer caching)
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY . .

# Set ownership BEFORE switching user
RUN chown -R grader:docker /app

# Switch to non-root user
USER grader

EXPOSE 8000

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]