FROM alpine:3.20

RUN apk add --no-cache mariadb-client tzdata

COPY backup.sh /usr/local/bin/backup.sh
COPY infra/backup/crontab /etc/crontabs/root

RUN chmod 0755 /usr/local/bin/backup.sh \
    && chmod 0600 /etc/crontabs/root

CMD ["crond", "-f", "-l", "2"]

