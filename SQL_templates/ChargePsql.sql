--
-- Create geometry, primary keys and index
--
-- Copyright (c) 2016 Daniel Thonon <d.thonon9@gmail.com>
-- All rights reserved.
--
-- Redistribution and use in source and binary forms, with or without modification,
-- are permitted provided that the following conditions are met:
-- 1. Redistributions of source code must retain the above copyright notice,
-- this list of conditions and the following disclaimer.
-- 2. Redistributions in binary form must reproduce the above copyright notice,
-- this list of conditions and the following disclaimer in the documentation and/or
-- other materials provided with the distribution.
-- 3. Neither the name of the copyright holder nor the names of its contributors
-- may be used to endorse or promote products derived from this software without
-- specific prior written permission.
--
-- THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 -- ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
-- WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
-- IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
-- INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
-- BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA,
-- OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
-- WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
-- ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
-- POSSIBILITY OF SUCH DAMAGE.
--
-- @license http://www.opensource.org/licenses/mit-license.html MIT License

-- Output format for counting lines in tables
\pset format unaligned
\pset expanded on
\pset fieldsep :

-- Create indexes on entities table
-- Primary key
ALTER TABLE entities DROP CONSTRAINT IF EXISTS pk_entities;
ALTER TABLE entities ADD CONSTRAINT pk_entities PRIMARY KEY(id);

VACUUM ANALYZE entities;
SELECT COUNT(id) AS "entities" FROM entities;

-- Create indexes on export_organizations table
-- Primary key
ALTER TABLE export_organizations DROP CONSTRAINT IF EXISTS pk_export_organizations;
ALTER TABLE export_organizations ADD CONSTRAINT pk_export_organizations PRIMARY KEY(id);

VACUUM ANALYZE export_organizations;
SELECT COUNT(id) AS "export_organizations" FROM export_organizations;

-- Create indexes on families table
-- Primary key
ALTER TABLE families DROP CONSTRAINT IF EXISTS pk_families;
ALTER TABLE families ADD CONSTRAINT pk_families PRIMARY KEY(id);

VACUUM ANALYZE families;
SELECT COUNT(id) AS "families" FROM families;

-- Create indexes on grids table
-- Primary key
ALTER TABLE grids DROP CONSTRAINT IF EXISTS pk_grids;
ALTER TABLE grids ADD CONSTRAINT pk_grids PRIMARY KEY(id);

-- Index: idx_grids_name
DROP INDEX IF EXISTS idx_grids_name;
CREATE INDEX idx_grids_name ON grids
    USING btree (name);

VACUUM ANALYZE grids;
SELECT COUNT(id) AS "grids" FROM grids;

-- Create indexes on local_admin_units table
-- Primary key
ALTER TABLE local_admin_units DROP CONSTRAINT IF EXISTS pk_local_admin_units;
ALTER TABLE local_admin_units ADD CONSTRAINT pk_local_admin_units PRIMARY KEY(id);

-- Index: idx_local_admin_units_name
DROP INDEX IF EXISTS idx_local_admin_units_name;
CREATE INDEX idx_local_admin_units_name ON local_admin_units
    USING btree (name COLLATE pg_catalog."default" varchar_pattern_ops);

VACUUM ANALYZE local_admin_units;
SELECT COUNT(id) AS "local_admin_units" FROM local_admin_units;

-- Create indexes on observations table
-- Primary key
ALTER TABLE observations DROP CONSTRAINT IF EXISTS pk_observations;
ALTER TABLE observations ADD CONSTRAINT pk_observations PRIMARY KEY(id_sighting);

-- Geometry columns & index: idx_the_geom_gist
DROP INDEX IF EXISTS idx_observations_geom_gist;
ALTER TABLE observations DROP COLUMN IF EXISTS the_geom CASCADE;
ALTER TABLE observations DROP COLUMN IF EXISTS coord_lon_l93 CASCADE;
ALTER TABLE observations DROP COLUMN IF EXISTS coord_lat_l93 CASCADE;
\o /dev/null
SELECT AddGeometryColumn('observations', 'the_geom', 2154, 'POINT', 2);
\o
UPDATE observations
    SET the_geom = ST_Transform(ST_SetSRID(ST_MakePoint(observer_coord_lon, observer_coord_lat), 4326), 2154);
CREATE INDEX idx_observations_geom_gist ON observations
    USING GIST (the_geom);
ALTER TABLE observations ADD coord_lon_l93 double precision;
ALTER TABLE observations ADD coord_lat_l93 double precision;
UPDATE observations SET coord_lon_l93 = ST_X(the_geom);
UPDATE observations SET coord_lat_l93 = ST_Y(the_geom);

-- Index: idx_observations_altitude
DROP INDEX IF EXISTS idx_observations_altitude;
CREATE INDEX idx_observations_altitude ON observations
    USING btree (altitude);

-- Index: idx_atlas_grid_name
DROP INDEX IF EXISTS idx_observations_atlas_grid_name;
CREATE INDEX idx_observations_atlas_grid_name ON observations
    USING btree (atlas_grid_name);

-- Index: idx_observations_date
DROP INDEX IF EXISTS idx_observations_date;
CREATE INDEX idx_observations_date ON observations
    USING btree (date);

-- Computed colum: date_year
ALTER TABLE observations DROP COLUMN IF EXISTS date_year CASCADE;
ALTER TABLE observations ADD date_year integer;
UPDATE observations SET date_year = EXTRACT(YEAR FROM date);
-- Index: idx_observations_date_year
DROP INDEX IF EXISTS idx_observations_date_year;
CREATE INDEX idx_observations_date_year ON observations
    USING btree (date_year);

-- Index: idx_observations_latin_species
DROP INDEX IF EXISTS idx_observations_latin_species;
CREATE INDEX idx_observations_latin_species ON observations
    USING btree (latin_species COLLATE pg_catalog."default" varchar_pattern_ops);

-- Index: idx_observations_municipality
DROP INDEX IF EXISTS idx_observations_municipality;
CREATE INDEX idx_observations_municipality ON observations
    USING btree (municipality COLLATE pg_catalog."default" varchar_pattern_ops);

-- Index: idx_observations_insee
DROP INDEX IF EXISTS idx_observations_insee;
CREATE INDEX idx_observations_insee ON observations
    USING btree (insee);

-- Index: idx_observations_name_species
DROP INDEX IF EXISTS idx_observations_name_species;
CREATE INDEX idx_observations_name_species ON observations
    USING btree (name_species COLLATE pg_catalog."default" varchar_pattern_ops);

-- Index: idx_observations_comment
DROP INDEX IF EXISTS idx_observations_comment;
CREATE INDEX idx_observations_comment ON observations
    USING btree (comment COLLATE pg_catalog."default" varchar_pattern_ops);

-- Index: idx_observations_entity_short_name
DROP INDEX IF EXISTS idx_observations_entity_short_name;
CREATE INDEX idx_observations_entity_short_name ON observations
    USING btree (entity COLLATE pg_catalog."default");

-- Index: idx_observations_has_death
DROP INDEX IF EXISTS idx_observations_has_death;
CREATE INDEX idx_observations_has_death ON observations
    USING btree (has_death);

-- Index: idx_name
DROP INDEX IF EXISTS idx_observations_name;
CREATE INDEX idx_observations_name ON observations
    USING btree (name);

VACUUM ANALYZE observations;
SELECT COUNT(id_sighting) AS "observations" FROM observations;

-- Create indexes on places table
-- Removez duplicate rows (download bug ?)
 DELETE FROM places
    WHERE id IN (SELECT id
                    FROM (SELECT id, ROW_NUMBER() OVER (partition BY id) AS rnum
                            FROM places) t
                    WHERE t.rnum > 1);
-- Primary key
ALTER TABLE places DROP CONSTRAINT IF EXISTS pk_places;
ALTER TABLE places ADD CONSTRAINT pk_places PRIMARY KEY(id);

-- Geometry columns & index: idx_places_geom_gist
DROP INDEX IF EXISTS idx_places_geom_gist;
ALTER TABLE places DROP COLUMN IF EXISTS the_geom CASCADE;
ALTER TABLE places DROP COLUMN IF EXISTS coord_lon_l93 CASCADE;
ALTER TABLE places DROP COLUMN IF EXISTS coord_lat_l93 CASCADE;
\o /dev/null
SELECT AddGeometryColumn('places', 'the_geom', 2154, 'POINT', 2);
\o
UPDATE places
    SET the_geom = ST_Transform(ST_SetSRID(ST_MakePoint(coord_lon, coord_lat), 4326), 2154);
CREATE INDEX idx_places_geom_gist ON places
    USING GIST (the_geom);
ALTER TABLE places ADD coord_lon_l93 double precision;
ALTER TABLE places ADD coord_lat_l93 double precision;
UPDATE places SET coord_lon_l93 = ST_X(the_geom);
UPDATE places SET coord_lat_l93 = ST_Y(the_geom);

VACUUM ANALYZE places;
SELECT COUNT(id) AS "places" FROM places;

-- Create indexes on species table
-- Removez duplicate rows (download bug ?)
 DELETE FROM species
    WHERE id IN (SELECT id
                    FROM (SELECT id, ROW_NUMBER() OVER (partition BY id) AS rnum
                            FROM species) t
                    WHERE t.rnum > 1);
-- Primary key
ALTER TABLE species DROP CONSTRAINT IF EXISTS pk_species;
ALTER TABLE species ADD CONSTRAINT pk_species PRIMARY KEY(id);

VACUUM ANALYZE species;
SELECT COUNT(id) AS "species" FROM species;

-- Create indexes on taxo_groups table
-- Primary key
ALTER TABLE taxo_groups DROP CONSTRAINT IF EXISTS pk_taxo_groups;
ALTER TABLE taxo_groups ADD CONSTRAINT pk_taxo_groups PRIMARY KEY(id);

VACUUM ANALYZE taxo_groups;
SELECT COUNT(id) AS "taxo_groups" FROM taxo_groups;

-- Create indexes on territorial_units table
-- Primary key
ALTER TABLE territorial_units DROP CONSTRAINT IF EXISTS pk_territorial_units;
ALTER TABLE territorial_units ADD CONSTRAINT pk_territorial_units PRIMARY KEY(id);

VACUUM ANALYZE territorial_units;
SELECT COUNT(id) AS "territorial_units" FROM territorial_units;
