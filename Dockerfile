FROM php:7.0-cli

RUN apt-get update && apt-get install -y libc-client-dev libkrb5-dev supervisor && rm -r /var/lib/apt/lists/*
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl && docker-php-ext-install imap

COPY mailrig.php /usr/local/bin/mailrig
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint

#RUN useradd -r -u 1000 -d /etc/mailrig mailrig
#USER mailrig

WORKDIR /etc/mailrig

ENTRYPOINT ["docker-entrypoint"]
