FROM python:3.12-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates iputils-ping \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 10001 insight-agent \
    && mkdir -p /var/lib/insight-agent \
    && chown insight-agent:insight-agent /var/lib/insight-agent

WORKDIR /opt/insight

COPY monitoring/python_monitoring/requirements.txt /tmp/insight-python-requirements.txt
RUN python3 -m pip install --no-cache-dir -r /tmp/insight-python-requirements.txt

COPY monitoring/python_monitoring /opt/insight/python_monitoring
COPY monitoring/agent /opt/insight/agent
COPY LICENSE /usr/share/licenses/insight/LICENSE

ENV PYTHONPATH=/opt/insight
ENV PYTHONUNBUFFERED=1

USER insight-agent

ENTRYPOINT ["python3", "/opt/insight/agent/agent.py"]
