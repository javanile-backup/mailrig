FROM php:7.0-cli

RUN apt-get update && apt-get install -y libc-client-dev libkrb5-dev && rm -r /var/lib/apt/lists/*
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl && docker-php-ext-install imap

COPY mailman.php /usr/local/bin/mailman

ENTRYPOINT ["mailman"]
