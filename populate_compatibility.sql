-- Get the latest game version first
SET @latest_game_version = (
    SELECT version
    FROM valheim_updates
    WHERE version IS NOT NULL AND version != ''
    ORDER BY published_at DESC
    LIMIT 1
);

-- Insert all mods into compatibility table
INSERT INTO mod_compatibility (
    mod_author,
    mod_name,
    mod_version,
    game_version,
    status,
    notes,
    tested_by
)
SELECT
    m.author,
    m.name,
    m.version,
    @latest_game_version,
    CASE
        WHEN m.deprecated = 1 THEN 'incompatible'
        ELSE 'compatible'
    END as status,
    CASE
        WHEN m.deprecated = 1 THEN 'Mod marked as deprecated on Thunderstore'
        ELSE 'Auto-populated based on mod status'
    END as notes,
    'system' as tested_by
FROM mods m
WHERE @latest_game_version IS NOT NULL
ON DUPLICATE KEY UPDATE
    status = CASE
        WHEN m.deprecated = 1 AND VALUES(status) = 'compatible' THEN 'incompatible'
        ELSE mod_compatibility.status
    END,
    notes = CASE
        WHEN m.deprecated = 1 AND VALUES(status) = 'compatible' THEN 'Mod marked as deprecated on Thunderstore'
        ELSE mod_compatibility.notes
    END,
    tested_date = CASE
        WHEN m.deprecated = 1 AND VALUES(status) = 'compatible' THEN CURRENT_TIMESTAMP
        ELSE mod_compatibility.tested_date
    END;