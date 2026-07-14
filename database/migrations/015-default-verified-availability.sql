UPDATE monitoring_calc_settings
SET default_calc_method = 'interval_capped'
WHERE singleton_id = 1
  AND default_calc_method = 'time_weighted';
