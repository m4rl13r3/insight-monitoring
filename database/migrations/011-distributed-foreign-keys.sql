DELETE ma
FROM monitoring_assignments ma
LEFT JOIN sites site ON site.id = ma.site_id
LEFT JOIN monitoring_nodes node ON node.id = ma.node_id
WHERE site.id IS NULL OR node.id IS NULL;

DELETE mar
FROM monitoring_agent_requests mar
LEFT JOIN monitoring_nodes node ON node.id = mar.node_id
WHERE node.id IS NULL;

DELETE mab
FROM monitoring_agent_batches mab
LEFT JOIN monitoring_nodes node ON node.id = mab.node_id
WHERE node.id IS NULL;

DELETE mo
FROM monitoring_observations mo
LEFT JOIN sites site ON site.id = mo.site_id
LEFT JOIN monitoring_nodes node ON node.id = mo.node_id
WHERE site.id IS NULL OR node.id IS NULL;

DELETE mc
FROM monitoring_consensus_current mc
LEFT JOIN sites site ON site.id = mc.site_id
WHERE site.id IS NULL;

DELETE mcs
FROM monitoring_consensus_snapshots mcs
LEFT JOIN sites site ON site.id = mcs.site_id
WHERE site.id IS NULL;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monitoring_assignments' AND COLUMN_NAME='site_id' AND REFERENCED_TABLE_NAME='sites' AND REFERENCED_COLUMN_NAME='id'),
    'SELECT 1',
    'ALTER TABLE monitoring_assignments ADD CONSTRAINT fk_monitoring_assignments_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monitoring_assignments' AND COLUMN_NAME='node_id' AND REFERENCED_TABLE_NAME='monitoring_nodes' AND REFERENCED_COLUMN_NAME='id'),
    'SELECT 1',
    'ALTER TABLE monitoring_assignments ADD CONSTRAINT fk_monitoring_assignments_node FOREIGN KEY (node_id) REFERENCES monitoring_nodes (id) ON DELETE CASCADE'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monitoring_agent_requests' AND COLUMN_NAME='node_id' AND REFERENCED_TABLE_NAME='monitoring_nodes' AND REFERENCED_COLUMN_NAME='id'),
    'SELECT 1',
    'ALTER TABLE monitoring_agent_requests ADD CONSTRAINT fk_monitoring_agent_requests_node FOREIGN KEY (node_id) REFERENCES monitoring_nodes (id) ON DELETE CASCADE'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monitoring_agent_batches' AND COLUMN_NAME='node_id' AND REFERENCED_TABLE_NAME='monitoring_nodes' AND REFERENCED_COLUMN_NAME='id'),
    'SELECT 1',
    'ALTER TABLE monitoring_agent_batches ADD CONSTRAINT fk_monitoring_agent_batches_node FOREIGN KEY (node_id) REFERENCES monitoring_nodes (id) ON DELETE CASCADE'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monitoring_observations' AND COLUMN_NAME='site_id' AND REFERENCED_TABLE_NAME='sites' AND REFERENCED_COLUMN_NAME='id'),
    'SELECT 1',
    'ALTER TABLE monitoring_observations ADD CONSTRAINT fk_monitoring_observations_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monitoring_observations' AND COLUMN_NAME='node_id' AND REFERENCED_TABLE_NAME='monitoring_nodes' AND REFERENCED_COLUMN_NAME='id'),
    'SELECT 1',
    'ALTER TABLE monitoring_observations ADD CONSTRAINT fk_monitoring_observations_node FOREIGN KEY (node_id) REFERENCES monitoring_nodes (id) ON DELETE CASCADE'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monitoring_consensus_current' AND COLUMN_NAME='site_id' AND REFERENCED_TABLE_NAME='sites' AND REFERENCED_COLUMN_NAME='id'),
    'SELECT 1',
    'ALTER TABLE monitoring_consensus_current ADD CONSTRAINT fk_monitoring_consensus_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monitoring_consensus_snapshots' AND COLUMN_NAME='site_id' AND REFERENCED_TABLE_NAME='sites' AND REFERENCED_COLUMN_NAME='id'),
    'SELECT 1',
    'ALTER TABLE monitoring_consensus_snapshots ADD CONSTRAINT fk_monitoring_consensus_snapshot_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;
