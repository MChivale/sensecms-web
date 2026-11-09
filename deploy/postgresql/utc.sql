-- Older signed extensions and Core queries use the MySQL spelling of UTC time.
CREATE OR REPLACE FUNCTION public.utc_timestamp() RETURNS timestamp
LANGUAGE sql STABLE PARALLEL SAFE
RETURN statement_timestamp() AT TIME ZONE 'UTC';

CREATE OR REPLACE FUNCTION public.sense_field(needle text, VARIADIC choices text[]) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE
RETURN coalesce(array_position(choices, needle), 0);

CREATE OR REPLACE FUNCTION public.sense_unique_text(items text[]) RETURNS text[]
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT array_agg(value ORDER BY first_position) FROM (
        SELECT value, min(position) AS first_position
        FROM unnest(items) WITH ORDINALITY AS input(value, position) GROUP BY value
    ) AS unique_items
$$;

CREATE OR REPLACE FUNCTION public.date_format(value timestamptz, pattern text) RETURNS timestamp
LANGUAGE plpgsql STABLE STRICT AS $$
BEGIN
    IF pattern <> '%Y-%m-01 00:00:00' THEN RAISE EXCEPTION 'Unsupported CMS date format'; END IF;
    RETURN date_trunc('month', value AT TIME ZONE 'UTC');
END
$$;

CREATE OR REPLACE FUNCTION public.get_lock(lock_name text, timeout_seconds integer) RETURNS integer
LANGUAGE plpgsql VOLATILE STRICT AS $$
DECLARE
    key bigint := hashtextextended(current_database() || ':' || lock_name, 0);
    deadline timestamptz := clock_timestamp() + timeout_seconds * interval '1 second';
BEGIN
    LOOP
        IF pg_try_advisory_lock(key) THEN RETURN 1; END IF;
        IF clock_timestamp() >= deadline THEN RETURN 0; END IF;
        PERFORM pg_sleep(0.05);
    END LOOP;
END
$$;

CREATE OR REPLACE FUNCTION public.release_lock(lock_name text) RETURNS integer LANGUAGE sql VOLATILE STRICT
RETURN pg_advisory_unlock(hashtextextended(current_database() || ':' || lock_name, 0))::int;

-- The calendar and forms packages read keys such as $.source from JSON payloads.
CREATE OR REPLACE FUNCTION public.json_extract(document text, path text) RETURNS jsonb
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN jsonb_path_query_first(document::jsonb, path::jsonpath);

CREATE OR REPLACE FUNCTION public.json_unquote(value jsonb) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN value #>> '{}';
