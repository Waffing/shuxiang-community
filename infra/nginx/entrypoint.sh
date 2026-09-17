#!/bin/sh
set -eu

case "${DOMAIN:-}" in
    ''|*[!A-Za-z0-9.-]*)
        echo "DOMAIN must contain only letters, numbers, dots, and hyphens" >&2
        exit 1
        ;;
esac

certificate="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
private_key="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"
mkdir -p /etc/nginx/snippets /var/cache/nginx/fastcgi

cat > /etc/nginx/snippets/security-headers.conf <<'EOF'
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options SAMEORIGIN always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Content-Security-Policy "default-src 'self'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'; object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'" always;
add_header Cache-Control $forum2_cache_control always;
EOF

brotli_directives='brotli on;
    brotli_comp_level 5;
    brotli_types application/javascript application/json application/manifest+json application/xml font/ttf image/svg+xml text/css text/plain;'

cat > /etc/nginx/conf.d/default.conf <<EOF
map \$request_method \$forum2_write_key {
    default \$binary_remote_addr;
    GET "";
    HEAD "";
    OPTIONS "";
}
map \$arg_action \$forum2_sensitive_key {
    default "";
    login "\$binary_remote_addr:login";
    register "\$binary_remote_addr:register";
    publish "\$binary_remote_addr:publish";
    report "\$binary_remote_addr:report";
    dmca "\$binary_remote_addr:dmca";
}
limit_req_zone \$forum2_write_key zone=forum2_write:10m rate=30r/m;
limit_req_zone \$forum2_sensitive_key zone=forum2_sensitive:10m rate=5r/m;
map \$uri \$forum2_cache_control {
    default "";
    /sw.js "no-cache, no-store, must-revalidate";
    /app-update.json "no-cache, no-store, must-revalidate";
    /manifest.json "public, max-age=3600";
    ~^/protected-downloads/ "private, no-store";
    ~^/downloads/ "private, no-store";
    ~^/uploads/ "public, max-age=31536000, immutable";
    ~^/assets/ "public, max-age=31536000, immutable";
}

server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root /var/www/html/public;
    index index.php;
    include /etc/nginx/snippets/security-headers.conf;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type text/plain;
    }

    location = /healthz {
        access_log off;
        return 200 'ok';
        default_type text/plain;
    }
EOF

if [ -s "$certificate" ] && [ -s "$private_key" ]; then
    cat >> /etc/nginx/conf.d/default.conf <<EOF
    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ${DOMAIN};
    root /var/www/html/public;
    index index.php;

    ssl_certificate ${certificate};
    ssl_certificate_key ${private_key};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;
    ssl_stapling on;
    ssl_stapling_verify on;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    include /etc/nginx/snippets/security-headers.conf;

    ${brotli_directives}
EOF
else
    cat >> /etc/nginx/conf.d/default.conf <<'EOF'
    include /etc/nginx/snippets/app-locations.conf;
}
EOF
fi

if [ -s "$certificate" ] && [ -s "$private_key" ]; then
    cat >> /etc/nginx/conf.d/default.conf <<'EOF'
    location = /healthz {
        access_log off;
        return 200 'ok';
        default_type text/plain;
    }

    include /etc/nginx/snippets/app-locations.conf;
}
EOF
fi

cat > /etc/nginx/snippets/app-locations.conf <<'EOF'
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location = /sw.js {
    try_files $uri =404;
}

location = /manifest.json {
    try_files $uri =404;
}

location = /app-update.json {
    try_files $uri =404;
}

location = /robots.txt { rewrite ^ /seo.php last; }
location = /sitemap.xml { rewrite ^ /seo.php last; }
location ~ ^/(?:software|platform|category)/[^/]+/?$ { rewrite ^ /index.php last; }

location ^~ /protected-downloads/ {
    internal;
    alias /var/www/downloads/;
    sendfile on;
}

location ^~ /uploads/ {
    if ($uri !~ "^/uploads/icons/[A-Za-z0-9.-]+\.webp$") { return 404; }
    try_files $uri =404;
}

location ^~ /downloads/ {
    if ($uri !~ "^/downloads/resource-forum-v[0-9]+\.[0-9]+\.[0-9]+-release\.apk$") { return 404; }
    try_files $uri =404;
}

location ~* \.apk$ { return 404; }

location ~* \.(?:css|js|svg|webp|png|jpe?g|gif|ico|woff2?|ttf)$ {
    access_log off;
    try_files $uri =404;
}

location ~ ^/(?:index|api|download|health|seo)\.php$ {
    limit_req zone=forum2_write burst=10 nodelay;
    limit_req zone=forum2_sensitive burst=5 nodelay;
    limit_req_status 429;
    try_files $uri =404;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_param HTTPS $https if_not_empty;
    fastcgi_param HTTP_PROXY "";
    fastcgi_pass app:9000;
    fastcgi_connect_timeout 5s;
    fastcgi_read_timeout 60s;
}

location ~* \.php { return 404; }

location ~ /\. {
    deny all;
}
EOF

nginx -t

(
    while sleep 21600; do
        nginx -s reload || true
    done
) &

exec nginx -g 'daemon off;'
