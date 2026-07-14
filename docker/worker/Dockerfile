FROM python:3.12-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl iputils-ping mtr-tiny snmp traceroute \
    && rm -rf /var/lib/apt/lists/*

COPY monitoring/python_monitoring/requirements.txt /tmp/insight-python-requirements.txt

RUN python3 -m pip install --no-cache-dir -r /tmp/insight-python-requirements.txt

RUN python3 -m playwright install --with-deps chromium

WORKDIR /var/www/insight

COPY . /var/www/insight
COPY licenses /usr/share/licenses/insight

RUN groupadd --system insight \
    && useradd --system --gid insight --home-dir /var/www/insight insight \
    && mkdir -p monitoring/logs monitoring/runtime /var/lib/insight/diagnostics /var/lib/insight/incident-attachments \
    && chown -R insight:insight monitoring/logs monitoring/runtime /var/lib/insight

USER insight

CMD ["python3", "monitoring/python_monitoring/scheduler.py"]
