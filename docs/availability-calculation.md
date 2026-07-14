# Availability calculation

Think of Insight as a small robot that regularly asks a service, "are you okay?" Availability is the score that says how often the robot heard a good answer.

## Recommended default: interval capped

Leave the Automatic option enabled unless there is a specific reason not to. If the robot asks every minute, it trusts an answer for two minutes at most. After that, it says "I don't know" instead of pretending that the service is working or broken. Unknown time counts as neither good nor bad.

This is the right choice for nearly every monitor. It avoids a false score when Insight itself could not ask the question.

Maintenance and unknown time are excluded from the availability denominator. Degraded time remains available but contributes half weight to the health score.

## Other methods

| Option in Insight | What it means | Use it only when |
| --- | --- | --- |
| Count all elapsed time (`time_weighted`) | The latest result remains true until the next result. A five-minute gap keeps the same state for five minutes. | Checks arrive very regularly and an interrupted worker should not create unknown time. |
| Count checks (`sample_ratio`) | Every result weighs the same. Ninety-eight successes and two failures mean 98%. | You import individual checks and elapsed time should not matter. |
| Require continuous monitoring (`strict_sla`) | A degraded or missing result counts as unavailable. | The monitoring path itself is part of a strict contractual commitment. |

`time_weighted` can overstate the duration of the last known state after an interrupted worker. Prefer `interval_capped` unless carrying the last observation indefinitely is an intentional policy.

`sample_ratio` is easy to explain but can be biased when the probing frequency changes during an incident. Reinforced monitoring therefore makes it a poor default for monitors that temporarily run faster after recovery.

`strict_sla` deliberately penalizes missing observations. It is useful when the monitoring system itself must be continuously trustworthy, but it can report lower availability during worker or network outages that did not affect the monitored service.

Historical reports created with the former sixty-bucket calculation remain readable. Insight no longer offers that compatibility calculation for new monitors.

## Traffic-weighted availability

For a service with request counters, availability should be calculated from successful operations divided by total operations. This represents the experience of actual traffic better than synthetic probes when load changes through the day or only part of a replicated service fails.

Insight does not expose this as a monitor calculation because a black-box monitor has no request volume to weight. It belongs to a metrics-backed SLO integration, using a source such as Prometheus or OpenTelemetry, rather than to a synthetic probe.

## Daily aggregation

Daily availability is calculated from the actual hourly basis rather than averaging hourly percentages. Mixed historical methods remain visible in aggregate metadata, and response times are weighted by their real sample count. This prevents a nearly empty hour from having the same influence as a fully observed hour.
