#!/usr/bin/env bash

set -u

cd /var/www/insight
mkdir -p monitoring/logs monitoring/runtime

interval="${INSIGHT_MONITOR_INTERVAL_SEC:-60}"
distributed_mode="${INSIGHT_DISTRIBUTED_MODE:-standalone}"
case "$interval" in
    ''|*[!0-9]*) interval=60 ;;
esac
if [ "$interval" -lt 10 ]; then
    interval=10
fi

next_monitor=0
last_hour=""
last_day=""

while true; do
    now="$(date +%s)"
    touch monitoring/runtime/scheduler.heartbeat
    hour_key="$(date +%Y%m%d%H)"
    day_key="$(date +%Y%m%d)"

    if [ "$now" -ge "$next_monitor" ]; then
        if [ "$distributed_mode" = "hub" ]; then
            php monitoring/distributed_consensus.php >> monitoring/logs/scheduler.log 2>&1
        else
            php monitoring/monitoring.php >> monitoring/logs/scheduler.log 2>&1
        fi
        next_monitor=$((now + interval))
    fi

    if [ "$hour_key" != "$last_hour" ]; then
        hourly_ok=1
        php monitoring/hourly.php >> monitoring/logs/scheduler.log 2>&1 || hourly_ok=0
        last_hour="$hour_key"
    else
        hourly_ok=1
    fi

    if [ "$day_key" != "$last_day" ]; then
        daily_ok=1
        php monitoring/daily.php >> monitoring/logs/scheduler.log 2>&1 || daily_ok=0
        if [ "$hourly_ok" -eq 1 ] && [ "$daily_ok" -eq 1 ]; then
            php monitoring/retention.php >> monitoring/logs/scheduler.log 2>&1
        fi
        last_day="$day_key"
    fi

    sleep 5
done
