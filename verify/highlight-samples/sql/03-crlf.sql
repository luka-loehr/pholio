-- Migration 2026_09_01_release_board (CRLF line endings)
BEGIN TRANSACTION;

CREATE TABLE release_slot (
    id              SERIAL PRIMARY KEY,
    day             DATE NOT NULL,
    slot            SMALLINT NOT NULL CHECK (slot > 0 AND slot <= 10),
    team_id         INTEGER NOT NULL REFERENCES team (id),
    service         VARCHAR(40) NOT NULL,
    owner_old       VARCHAR(8),
    owner_new       VARCHAR(8),
    kind            TEXT NOT NULL DEFAULT 'Release'
                    CHECK (kind IN ('Release', 'Cancelled', 'Rollback', 'Moved')),
    note            TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_release_slot UNIQUE (day, slot, team_id)
);

COMMENT ON TABLE release_slot IS 'Daily changes to the release plan — café';

CREATE INDEX ON release_slot USING btree (day, team_id);

INSERT INTO release_slot (day, slot, team_id, service, owner_old, owner_new, kind, note)
SELECT DATE '2026-09-14' + (n % 5), (n % 10) + 1, 1 + (n % 12), 'Search', 'JDO', NULL, 'Cancelled', E'Line 1\nLine 2'
FROM generate_series(1, 50) AS g(n)
ON CONFLICT (day, slot, team_id) DO NOTHING;

UPDATE release_slot r
SET note = concat_ws(' – ', r.note, 'Tasks in the tracker')
FROM team t
WHERE t.id = r.team_id
  AND t.level >= 10
  AND r.kind = 'Cancelled'
RETURNING r.id, r.note;

CREATE FUNCTION cancelled_count(p_day date) RETURNS integer
LANGUAGE sql STABLE AS $$
    SELECT count(*)::int FROM release_slot WHERE day = p_day AND kind = 'Cancelled';
$$;

GRANT SELECT ON release_slot TO docs_reader;
COMMIT;
