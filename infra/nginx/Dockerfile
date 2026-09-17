FROM alpine:3.20

RUN apk add --no-cache \
    nginx=1.26.3-r0 \
    nginx-mod-http-brotli=1.26.3-r0 \
    tzdata

COPY infra/nginx/nginx.conf /etc/nginx/nginx.conf
COPY infra/nginx/entrypoint.sh /usr/local/bin/forum-nginx-entrypoint

RUN chmod 0755 /usr/local/bin/forum-nginx-entrypoint

ENTRYPOINT ["/usr/local/bin/forum-nginx-entrypoint"]
