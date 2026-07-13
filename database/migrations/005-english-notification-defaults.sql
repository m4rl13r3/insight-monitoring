UPDATE notification_templates
SET title_template = '[{{ app_name }}] Test from {{ channel_name }}',
    body_template = 'This is a test message sent by {{ app_name }} at {{ timestamp }}.'
WHERE event_key = 'test'
  AND title_template = '[{{ app_name }}] Test de {{ channel_name }}'
  AND body_template = 'Ceci est un message de test envoyé par {{ app_name }} à {{ timestamp }}.';

UPDATE notification_templates
SET title_template = '[{{ app_name }}] {{ domain }} is offline',
    body_template = '{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} unavailable: {{ sites }}. {{ message }}'
WHERE event_key = 'monitor_down'
  AND title_template = '[{{ app_name }}] {{ domain }} est hors ligne'
  AND body_template = '{{ count }} service{% if count > 1 %}s sont{% else %} est{% endif %} indisponible{% if count > 1 %}s{% endif %} : {{ sites }}. {{ message }}';

UPDATE notification_templates
SET title_template = '[{{ app_name }}] {{ domain }} is back online',
    body_template = '{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} back online: {{ sites }}. {{ message }}'
WHERE event_key = 'monitor_up'
  AND title_template = '[{{ app_name }}] {{ domain }} est rétabli'
  AND body_template = '{{ count }} service{% if count > 1 %}s sont{% else %} est{% endif %} de retour en ligne : {{ sites }}. {{ message }}';

UPDATE notification_templates
SET title_template = '[{{ app_name }}] Incident opened - {{ domain }}',
    body_template = 'An incident is open for {{ sites }}. {{ message }}'
WHERE event_key = 'incident_open'
  AND title_template = '[{{ app_name }}] Incident ouvert · {{ domain }}'
  AND body_template = 'Un incident est ouvert pour {{ sites }}. {{ message }}';

UPDATE notification_templates
SET title_template = '[{{ app_name }}] Incident resolved - {{ domain }}',
    body_template = 'The incident affecting {{ sites }} is resolved. {{ message }}'
WHERE event_key = 'incident_resolved'
  AND title_template = '[{{ app_name }}] Incident résolu · {{ domain }}'
  AND body_template = 'L’incident concernant {{ sites }} est résolu. {{ message }}';
