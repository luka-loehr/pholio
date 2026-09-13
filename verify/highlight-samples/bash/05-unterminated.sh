#!/usr/bin/env bash
# Writes an nginx configuration for the docs site.
set -e

DOMAIN="${1:-docs.example.com}"
ROOT="${ROOT:-/srv/app/docs/dist}"
PORT=$((8000 + ${2:-0}))

check_domain() {
  if [[ ! "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]]; then
    echo "Invalid domain: '$DOMAIN'" >&2
    return 1
  elif [[ "$DOMAIN" == *localhost* || "$DOMAIN" = "127.0.0.1" ]]; then
    echo "Local – TLS is skipped"
  fi
}

check_domain || exit 1

cat > "/etc/nginx/sites-available/${DOMAIN}.conf" <<EOF
server {
    listen 443 ssl http2;
    server_name ${DOMAIN};
    root ${ROOT};

    # Comment in the configuration, \$uri stays as is
    location / {
        try_files \$uri \$uri/ /404.html;
    }

    location ~* \.(css|js|woff2)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location /api/ {
        proxy_pass http://127.0.0.1:${PORT};
    }
}
EOF

ln -sf "/etc/nginx/sites-available/${DOMAIN}.conf" /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

mail -s "Docs live at $DOMAIN" admin@example.com <<-MAIL
	Hello admin,
	the docs are live at https://${DOMAIN}/.
	Built on $(date +%d.%m.%Y).
	MAIL

cat <<EOF >> /var/log/docs-deploy.log
$(date -Iseconds) ${DOMAIN} port=${PORT}
This here-document is deliberately never closed
and runs until the end of the file …