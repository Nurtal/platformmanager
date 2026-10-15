FROM php:8.3-apache

LABEL author="GenOuest <support@genouest.org>" \
      description="Image for platform manager processes" \
      org.irisa.genouest.vendor="2021 IRISA" \
      homepage="https://github.com/Nurtal/platformmanager"


WORKDIR /var/www

VOLUME ["/var/www/platformmanager/data"]

# Install packages and PHP-extensions
# Note: PHP 8.0+ bundles c-client internally; libc-client2007e (removed in Debian Bookworm) is no longer needed
# Note: the base image is Debian Trixie, which renamed libjpeg62 -> libjpeg62-turbo,
#       libpng16-16 -> libpng16-16t64 and libzip4 -> libzip5. The old names abort the build.
RUN apt-get -q update && \
    DEBIAN_FRONTEND=noninteractive apt-get -yq --no-install-recommends install wget nano vim at git unzip \
    libfreetype6 libjpeg62-turbo libpng16-16t64 libx11-6 libxpm4 libzip5 libldap2-dev libkrb5-3 libssl3 && \
    BUILD_DEPS="libfreetype6-dev libjpeg62-turbo-dev libpng-dev libxpm-dev zlib1g-dev libzip-dev libkrb5-dev libssl-dev" && \
    DEBIAN_FRONTEND=noninteractive apt-get -yq --no-install-recommends install $BUILD_DEPS && \
    pecl install redis && docker-php-ext-enable redis && \
    docker-php-ext-configure gd \
    --with-jpeg \
    --with-xpm --with-freetype && \
    docker-php-ext-install gd pdo pdo_mysql mysqli zip ldap sockets && \
    a2enmod rewrite && \
    a2enmod headers && \
    a2enmod deflate && \
    a2enmod filter && \
    a2enmod proxy_http && \
    rm -rf /var/lib/apt/lists/* && \
    touch /var/log/php_errors.log && \
    chown www-data /var/log/php_errors.log && \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false -o APT::AutoRemove::SuggestsImportant=false $BUILD_DEPS && \
    rm -rf /var/lib/apt/lists/*

# ext/imap is built only when the base image can still provide uw-imap (c-client).
# Debian dropped it after bullseye and PHP moved ext/imap out of core in 8.4, so on a
# current base it simply cannot be built. Only bin/pfm-helpdesk.php, the mail poller
# behind the helpdesk service, calls imap_*: nothing in the web application does. The
# image is therefore built without it rather than not built at all. Moving the poller
# onto a maintained IMAP client library is its own piece of work; see the audit report.
RUN if apt-get -q update \
      && DEBIAN_FRONTEND=noninteractive apt-get -yq --no-install-recommends install libc-client-dev libkrb5-dev libssl-dev; then \
      docker-php-ext-configure imap --with-imap-ssl --with-kerberos && docker-php-ext-install imap; \
    else \
      echo "WARNING: ext-imap not built, no c-client on this base image: the helpdesk mail poller will not run"; \
    fi; \
    rm -rf /var/lib/apt/lists/*

RUN curl -o /tmp/composer-setup.php https://getcomposer.org/installer \
    && curl -o /tmp/composer-setup.sig https://composer.github.io/installer.sig \
    && php -r "if (hash('SHA384', file_get_contents('/tmp/composer-setup.php')) !== trim(file_get_contents('/tmp/composer-setup.sig'))) { unlink('/tmp/composer-setup.php'); echo 'Invalid installer' . PHP_EOL; exit(1); }" \
    && php /tmp/composer-setup.php --no-ansi --install-dir=/usr/local/bin --filename=composer --snapshot \
    && rm -f /tmp/composer-setup.*

ADD php_logs.ini /usr/local/etc/php/conf.d/php_logs.ini
ADD php_timezone.ini /usr/local/etc/php/conf.d/tz.ini
ADD php_pfm.ini /usr/local/etc/php/conf.d/pfm.ini
ADD php_opcache.ini /usr/local/etc/php/conf.d/opcache.ini
ADD apache2/platformmanager.conf /etc/apache2/conf-enabled/platformmanager.conf
ADD apache2/000-default.conf /etc/apache2/sites-enabled/000-default.conf

RUN mkdir -p /var/www/platformmanager \
    && chown -R www-data /var/www/platformmanager

# The sources are cloned at build time, not copied from the build context, so the
# repository decides which code ships. It defaults to this fork, which carries the
# security fixes; the upstream repository does not.
ARG REPOSITORY=https://github.com/Nurtal/platformmanager.git
ARG BRANCH=master

# install Platform-Manager sources
RUN git clone "$REPOSITORY" /tmp/platformmanager_git \
  && cd /tmp/platformmanager_git \
  && git checkout $BRANCH \
  && cp -r /tmp/platformmanager_git/data /opt \
  && cp -r /tmp/platformmanager_git/* /var/www/platformmanager \
  && cp /tmp/platformmanager_git/.htaccess /var/www/platformmanager \
  && cp -r /tmp/platformmanager_git/.git /var/www/platformmanager \
  && cp -r /tmp/platformmanager_git/.gitignore /var/www/platformmanager \
  && rm -rf /tmp/platformmanager_git \
  && cd /var/www/ \
  && mkdir platformmanager/tmp \
  && chown -R www-data: platformmanager \
  && rm -rf html \
  && ln -s platformmanager html \
  && mkdir -p /etc/platformmanager \
  && cp platformmanager/Config/conf.ini.sample /etc/platformmanager/ \
  && cp platformmanager/Config/conf.yaml.sample /etc/platformmanager/ \
  && cp platformmanager/Config/conf.yaml.sample /var/www/platformmanager/Config/conf.yaml

RUN if [ ! -e /var/www/platformmanager/data ]; then mkdir -p /var/www/platformmanager/data && chown -R www-data: /var/www/platformmanager/data; fi
RUN cd /var/www/platformmanager && composer install --ignore-platform-reqs

ENV MYSQL_HOST="mysql" \
    MYSQL_DBNAME="platformmanager" \
    MYSQL_USER="username" \
    MYSQL_PASS="password"

ADD entrypoint.sh /
ADD pfmextra-entrypoint.sh /
ADD setup.sh /

RUN chmod a+x /entrypoint.sh
RUN chmod a+x /pfmextra-entrypoint.sh
RUN chmod a+x /setup.sh

ADD https://github.com/ufoscout/docker-compose-wait/releases/download/2.12.1/wait /wait
RUN chmod +x /wait

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

CMD ["/entrypoint.sh"]
