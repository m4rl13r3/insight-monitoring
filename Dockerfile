FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        iputils-ping \
        libcurl4-openssl-dev \
        libonig-dev \
        libsqlite3-dev \
        libxml2-dev \
        python3 \
        python3-pip \
    && docker-php-ext-install curl mbstring mysqli pdo_sqlite xml \
    && rm -rf /var/lib/apt/lists/*

COPY monitoring/python_monitoring/requirements.txt /tmp/insight-python-requirements.txt

RUN python3 -m pip install --break-system-packages --no-cache-dir --target /opt/insight-pydeps -r /tmp/insight-python-requirements.txt

WORKDIR /var/www/insight

COPY . /var/www/insight
COPY licenses /usr/share/licenses/insight

RUN mkdir -p monitoring/logs monitoring/runtime public/logs /var/lib/insight-auth/sessions \
    && chown -R www-data:www-data monitoring/logs monitoring/runtime public/logs /var/lib/insight-auth \
    && chmod 700 /var/lib/insight-auth /var/lib/insight-auth/sessions

ENV PYTHONPATH=/opt/insight-pydeps
ENV PYTHON_BIN=/usr/bin/python3

USER www-data

CMD ["php-fpm"]
