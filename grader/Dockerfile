# ── DevEduHub Grader Service ──────────────────────────────────────────────────
# This image runs the FastAPI grading microservice.
# It needs git (to clone repos) and Docker CLI (to launch sibling containers).

FROM python:3.11-slim

# Install git + Docker CLI
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

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

RUN useradd -m -u 1000 grader && chown -R grader:grader /app
USER grader

EXPOSE 8000

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
