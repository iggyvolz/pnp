FROM php:8.1.0-alpine3.15 AS buildenv
RUN apk add bzip2-dev
RUN docker-php-ext-install bz2
# Quick composer install
RUN curl -o installer getcomposer.org/installer
RUN php installer
RUN php composer.phar global require composer/composer
RUN rm installer composer.phar
RUN mkdir /usr/src/pnp
WORKDIR /usr/src/pnp
COPY /composer.json .
COPY /composer.lock .
COPY bootstraps bootstraps
COPY src src
COPY bin bin
ENV PATH="/root/.composer/vendor/bin:${PATH}"
RUN composer install -a
RUN mkdir out
COPY extra-files.sh .

FROM buildenv AS main-build
# bin/pnp -b `pwd`/bin/pnp --vendor vendor $BOOTSTRAP_FILES $DATA_FILES --shebang '#!/usr/bin/env php' -b `pwd`/bin/pnpbin/out.php
RUN bin/pnp -b `pwd`/bin/pnp --vendor vendor `./extra-files.sh` --shebang '#!/usr/bin/env php' /pnp

FROM buildenv AS streamable-build
RUN bin/pnp -b `pwd`/bin/pnp --vendor vendor -s `./extra-files.sh` --shebang '#!/usr/bin/env php' /pnp

FROM buildenv AS gzip-build
RUN bin/pnp -b `pwd`/bin/pnp --vendor vendor -c gzip `./extra-files.sh` --shebang '#!/usr/bin/env php' /pnp

FROM buildenv AS gzip-streamable-build
RUN bin/pnp -b `pwd`/bin/pnp --vendor vendor -s -c gzip `./extra-files.sh` --shebang '#!/usr/bin/env php' /pnp

FROM buildenv AS bzip-build
RUN bin/pnp -b `pwd`/bin/pnp --vendor vendor -c bzip `./extra-files.sh` --shebang '#!/usr/bin/env php' /pnp

FROM buildenv AS bzip-streamable-build
RUN bin/pnp -b `pwd`/bin/pnp --vendor vendor -s -c bzip `./extra-files.sh` --shebang '#!/usr/bin/env php' /pnp

FROM scratch
COPY --from=main-build /pnp /pnp
COPY --from=streamable-build /pnp /pnp-streamable
COPY --from=gzip-build /pnp /pnp-gzip
COPY --from=gzip-streamable-build /pnp /pnp-gzip-streamable
COPY --from=bzip-build /pnp /pnp-bzip
COPY --from=bzip-streamable-build /pnp /pnp-bzip-streamable
