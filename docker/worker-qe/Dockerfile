FROM python:3.11-slim

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
 && rm -rf /var/lib/apt/lists/*

# Copy requirements from its real location in the repo
COPY app/Services/QeWorker/requirements.txt ./requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

# Copy the python package
COPY app/Services/QeWorker/app ./app

CMD ["python", "-m", "app.qe_worker"]
