# syntax=docker/dockerfile:1
#
# PHP devcontainer for fylgja-wp-sync, with Claude Code + egress firewall.
# Override versions via build args in devcontainer.json -> build.args.
# Available PHP tags: https://mcr.microsoft.com/en-us/product/devcontainers/php/tags
ARG PHP_VERSION=8.2
FROM mcr.microsoft.com/devcontainers/php:1-${PHP_VERSION}

ARG NODE_MAJOR=22

# System tools the firewall script needs, plus Node.js LTS from NodeSource.
# The base image ships a broken dl.yarnpkg.com source list (missing/expired
# GPG key) that makes `apt-get update` exit non-zero. We don't need yarn —
# remove any source list referencing it before refreshing package lists.
RUN grep -rl 'dl\.yarnpkg\.com' /etc/apt/sources.list.d/ 2>/dev/null \
      | xargs -r rm -f \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      iptables ipset dnsutils jq curl ca-certificates gnupg sudo \
 && mkdir -p /etc/apt/keyrings \
 && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
      | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
 && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
      > /etc/apt/sources.list.d/nodesource.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*

# Install Claude Code globally.
RUN npm install -g @anthropic-ai/claude-code

# Firewall script + scoped sudoers entry (vscode can run only this with no password).
# env_keep preserves proxy vars across sudo — without this, sudo strips
# HTTPS_PROXY/HTTP_PROXY and the script falls into direct-mode allowlist with
# whatever DNS returns at init time (which, behind a fake-IP DNS proxy like
# Clash, is garbage TEST-NET-2 addresses).
COPY init-firewall.sh /usr/local/bin/init-firewall.sh
RUN chmod 0755 /usr/local/bin/init-firewall.sh \
 && { echo 'Defaults env_keep += "HTTP_PROXY HTTPS_PROXY NO_PROXY http_proxy https_proxy no_proxy"'; \
      echo 'vscode ALL=(root) NOPASSWD: /usr/local/bin/init-firewall.sh'; } \
      > /etc/sudoers.d/init-firewall \
 && chmod 0440 /etc/sudoers.d/init-firewall

# Convenience alias for the YOLO invocation — install for both bash and zsh
# (MS devcontainer base ships oh-my-zsh for the vscode user).
RUN for rc in /home/vscode/.bashrc /home/vscode/.zshrc; do \
      [ -f "$rc" ] && echo "alias yolo='claude --dangerously-skip-permissions'" >> "$rc"; \
    done
