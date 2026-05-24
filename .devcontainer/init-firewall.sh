#!/usr/bin/env bash
# init-firewall.sh — lock egress to a small allowlist.
# Run as root (via sudo) once per container create.
set -euo pipefail

log() { printf '[init-firewall] %s\n' "$*" >&2; }

# --- Reset ---------------------------------------------------------------
iptables  -F; iptables  -X; iptables  -P INPUT ACCEPT; iptables  -P FORWARD DROP; iptables  -P OUTPUT DROP
ip6tables -F; ip6tables -X; ip6tables -P INPUT ACCEPT; ip6tables -P FORWARD DROP; ip6tables -P OUTPUT DROP
ipset destroy claude_allow 2>/dev/null || true
ipset create  claude_allow hash:ip family inet hashsize 1024 maxelem 65536

# --- Always-on egress ----------------------------------------------------
iptables -A OUTPUT -o lo -j ACCEPT
iptables -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
# DNS to whatever resolver the container was given.
awk '/^nameserver/ {print $2}' /etc/resolv.conf | while read -r ns; do
  iptables -A OUTPUT -p udp --dport 53 -d "$ns" -j ACCEPT
  iptables -A OUTPUT -p tcp --dport 53 -d "$ns" -j ACCEPT
done

# --- Mode: proxy if set, else direct allowlist ---------------------------
PROXY="${HTTPS_PROXY:-${https_proxy:-${HTTP_PROXY:-${http_proxy:-}}}}"

resolve_host() {  # prints one or more IPv4 addresses for a hostname
  dig +short A "$1" | grep -E '^[0-9.]+$' || true
}

if [[ -n "$PROXY" ]]; then
  # Parse host:port from URL like http://corp-proxy:3128
  proxy_hostport="${PROXY#*://}"
  proxy_hostport="${proxy_hostport%%/*}"
  proxy_host="${proxy_hostport%%:*}"
  proxy_port="${proxy_hostport##*:}"
  [[ "$proxy_port" == "$proxy_host" ]] && proxy_port=8080
  log "proxy mode: allowing $proxy_host:$proxy_port only"
  for ip in $(resolve_host "$proxy_host"); do
    iptables -A OUTPUT -p tcp -d "$ip" --dport "$proxy_port" -j ACCEPT
  done
else
  log "direct mode: building allowlist ipset"
  ALLOWLIST=(
    # ipinfo
    ipinfo.io
    # Anthropic
    api.anthropic.com
    # Node / npm
    registry.npmjs.org
    # GitHub
    api.github.com
    github.com
    codeload.github.com
    objects.githubusercontent.com
    raw.githubusercontent.com
    # Composer
    packagist.org
    repo.packagist.org
    getcomposer.org
    # WordPress
    api.wordpress.org
    downloads.wordpress.org
  )
  for host in "${ALLOWLIST[@]}"; do
    for ip in $(resolve_host "$host"); do
      ipset add claude_allow "$ip" 2>/dev/null || true
    done
  done
  iptables -A OUTPUT -p tcp -m set --match-set claude_allow dst --dport 443 -j ACCEPT
  iptables -A OUTPUT -p tcp -m set --match-set claude_allow dst --dport 80  -j ACCEPT
  iptables -A OUTPUT -p tcp -m set --match-set claude_allow dst --dport 22  -j ACCEPT  # github ssh
fi

# --- Smoke test ----------------------------------------------------------
if [[ -n "$PROXY" ]]; then
  curl -sS --max-time 5 -x "$PROXY" https://api.anthropic.com/v1/ -o /dev/null \
    && log "OK: reached api.anthropic.com via proxy" \
    || { log "FAIL: cannot reach api.anthropic.com via proxy"; exit 1; }
else
  curl -sS --max-time 5 https://api.anthropic.com/v1/ -o /dev/null \
    && log "OK: reached api.anthropic.com directly" \
    || { log "FAIL: cannot reach api.anthropic.com"; exit 1; }
fi

log "firewall initialized."
