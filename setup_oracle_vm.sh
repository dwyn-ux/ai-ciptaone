#!/usr/bin/env bash
# ==============================================================================
# Script Otomasi Setup Oracle Cloud Always Free VM (Ubuntu 22.04 / 24.04 ARM64)
# ==============================================================================
# Menyiapkan Docker, Docker Compose, dan membuka firewall iptables bawaan Oracle
# Jalankan dengan: sudo bash setup_oracle_vm.sh
# ==============================================================================

set -euo pipefail

echo "============================================================"
echo "🚀 MEMULAI SETUP ORACLE CLOUD ALWAYS FREE VM UNTUK AI ROUTER"
echo "============================================================"

# 1. Update paket sistem
echo "[*] Memperbarui paket sistem Ubuntu..."
apt-get update && apt-get upgrade -y
apt-get install -y ca-certificates curl gnupg lsb-release ufw git

# 2. Buka Firewall Bawaan Oracle Cloud (PENTING!)
# Ubuntu di Oracle Cloud memiliki default iptables yang memblokir port 80 & 443
echo "[*] Mengonfigurasi firewall iptables & UFW untuk membuka port 80 & 443..."
iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
netfilter-persistent save 2>/dev/null || true

ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# 3. Instal Docker Engine & Docker Compose
if ! command -v docker &> /dev/null; then
    echo "[*] Menginstal Docker Engine..."
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg

    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
      $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    systemctl enable --now docker
else
    echo "[*] Docker sudah terinstal."
fi

# 4. Tambahkan user ubuntu ke group docker
if id "ubuntu" &>/dev/null; then
    usermod -aG docker ubuntu
    echo "[*] User 'ubuntu' berhasil ditambahkan ke group docker."
fi

# 5. Siapkan direktori storage
mkdir -p storage/proofs
chmod -R 755 storage

echo ""
echo "============================================================"
echo "✅ SETUP VM ORACLE SELESAI!"
echo "Langkah berikutnya:"
echo "1. Pastikan Ingress Rules di Oracle Cloud Console sudah dibuka (port 80 & 443)."
echo "2. Konfigurasi file .env dan Caddyfile dengan domain Anda."
echo "3. Jalankan: docker compose up -d --build"
echo "============================================================"
