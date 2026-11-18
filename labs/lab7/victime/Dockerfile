FROM python:3.12-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      iproute2 curl openssh-client wireguard-tools \
 && rm -rf /var/lib/apt/lists/*
COPY victime.py /app/
CMD ["python3", "-u", "/app/victime.py"]