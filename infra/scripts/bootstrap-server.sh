#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps — server bootstrap
# =============================================================================
# Idempotent first-run setup for a fresh Ubuntu 24.04 host (bare VM or LXC).
# Run as root on the target server. Safe to re-run; each step checks state
# before acting.
#
#   sudo ./infra/scripts/bootstrap-server.sh
#
# What it does:
#   1. System update + baseline packages
#   2. Unattended security upgrades
#   3. UFW firewall (allow SSH + HTTP + HTTPS, deny the rest)
#   4. fail2ban for SSH brute-force protection
#   5. Docker Engine + Compose plugin
#   6. A non-root deploy user in the docker group
#   7. Basic sysctl + journald hardening
#
# What it does NOT do (intentionally — these are operator decisions):
#   - Configure DNS
#   - Write the .env file
#   - Clone the repository
#   - Start the application
#
# Configurable via environment variables (all optional):
#   DEPLOY_USER     name of the non-root deploy user        (default: deploy)
#   SSH_PORT        SSH port to allow through the firewall   (default: 22)
# =============================================================================

set -euo pipefail

DEPLOY_USER="${DEPLOY_USER:-deploy}"
SSH_PORT="${SSH_PORT:-22}"

log()  { printf '\033[1;34m[bootstrap]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[bootstrap]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[bootstrap]\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Must run as root (use sudo)."

# -----------------------------------------------------------------------------
log "1/7 — System update + baseline packages"
# -----------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
    ca-certificates \
    curl \
    git \
    gnupg \
    ufw \
    fail2ban \
    unattended-upgrades \
    htop \
    jq \
    make

# -----------------------------------------------------------------------------
log "2/7 — Unattended security upgrades"
# -----------------------------------------------------------------------------
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
systemctl enable --now unattended-upgrades >/dev/null 2>&1 || true

# -----------------------------------------------------------------------------
log "3/7 — UFW firewall"
# -----------------------------------------------------------------------------
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow "${SSH_PORT}/tcp" comment 'SSH' >/dev/null
ufw allow 80/tcp comment 'HTTP' >/dev/null
ufw allow 443/tcp comment 'HTTPS' >/dev/null
ufw allow 443/udp comment 'HTTP/3 QUIC' >/dev/null
ufw --force enable >/dev/null
log "    Firewall rules:"
ufw status numbered | sed 's/^/    /'

# -----------------------------------------------------------------------------
log "4/7 — fail2ban (SSH protection)"
# -----------------------------------------------------------------------------
cat > /etc/fail2ban/jail.local <<EOF
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
port    = ${SSH_PORT}
EOF
systemctl enable --now fail2ban >/dev/null 2>&1 || true
systemctl restart fail2ban || true

# -----------------------------------------------------------------------------
log "5/7 — Docker Engine + Compose plugin"
# -----------------------------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    # shellcheck source=/dev/null
    . /etc/os-release
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq \
        docker-ce \
        docker-ce-cli \
        containerd.io \
        docker-buildx-plugin \
        docker-compose-plugin
    systemctl enable --now docker >/dev/null 2>&1 || true
    log "    Docker installed: $(docker --version)"
else
    log "    Docker already present: $(docker --version)"
fi

# -----------------------------------------------------------------------------
log "6/7 — Deploy user '${DEPLOY_USER}'"
# -----------------------------------------------------------------------------
if ! id "${DEPLOY_USER}" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "${DEPLOY_USER}"
    log "    Created user ${DEPLOY_USER}"
else
    log "    User ${DEPLOY_USER} already exists"
fi
usermod -aG docker "${DEPLOY_USER}"
# Allow the deploy user to read/copy an SSH key if one was provisioned.
install -d -m 0700 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" \
    "/home/${DEPLOY_USER}/.ssh"
warn "    Add the deploy user's SSH public key to /home/${DEPLOY_USER}/.ssh/authorized_keys"

# -----------------------------------------------------------------------------
log "7/7 — Kernel + journald hardening"
# -----------------------------------------------------------------------------
cat > /etc/sysctl.d/99-bmssiteops.conf <<'EOF'
# Reduce swap pressure for a database-heavy host
vm.swappiness = 10
# Basic network hardening
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.tcp_syncookies = 1
EOF
sysctl --system >/dev/null 2>&1 || true

# Cap journal size so logs don't fill the disk
mkdir -p /etc/systemd/journald.conf.d
cat > /etc/systemd/journald.conf.d/size.conf <<'EOF'
[Journal]
SystemMaxUse=500M
EOF
systemctl restart systemd-journald || true

# -----------------------------------------------------------------------------
log "Done."
cat <<EOF

Next steps (performed by the operator, not this script):

  1. Add the deploy user's SSH public key:
       /home/${DEPLOY_USER}/.ssh/authorized_keys

  2. Point DNS A/AAAA records at this server for:
       <YOUR_DOMAIN>     and     <YOUR_MCP_DOMAIN>

  3. Switch to the deploy user and clone the repository:
       su - ${DEPLOY_USER}
       git clone https://github.com/D-S-Tech/BmsSiteOps.git
       cd BmsSiteOps

  4. Create the production env file:
       cp infra/compose/.env.prod.example .env
       # fill in every CHANGEME / <PLACEHOLDER>

  5. Deploy:
       ./infra/scripts/deploy.sh

See docs/DEPLOYMENT.md for the full runbook.
EOF
