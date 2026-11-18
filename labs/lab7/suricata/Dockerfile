FROM debian:trixie-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends suricata iptables \
 && rm -rf /var/lib/apt/lists/*