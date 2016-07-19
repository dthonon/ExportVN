
-- Create geometry, primary keys and index

-- Copyright (c) 2016 Daniel Thonon <d.thonon9@gmail.com>
-- All rights reserved.

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

-- @license http://www.opensource.org/licenses/mit-license.html MIT License

-- Create indexes on species table

-- Primary key
ALTER TABLE species DROP CONSTRAINT IF EXISTS pk_species;
ALTER TABLE species ADD CONSTRAINT pk_species PRIMARY KEY(id);

-- Create indexes on places table
-- Primary key
--ALTER TABLE places DROP CONSTRAINT IF EXISTS pk_places;
--ALTER TABLE places ADD CONSTRAINT pk_places PRIMARY KEY(id);

--
-- Index: public.idx_places_geom_gist
DROP INDEX IF EXISTS public.idx_places_geom_gist;
ALTER TABLE places DROP COLUMN IF EXISTS the_geom CASCADE;
ALTER TABLE places DROP COLUMN IF EXISTS coord_lon_l93 CASCADE;
ALTER TABLE places DROP COLUMN IF EXISTS coord_lat_l93 CASCADE;
SELECT AddGeometryColumn('public', 'places', 'the_geom', 2154, 'POINT', 2);
UPDATE places SET the_geom = ST_Transform(ST_SetSRID(ST_MakePoint(coord_lon, coord_lat), 4326), 2154);
CREATE INDEX idx_places_geom_gist ON places USING GIST (the_geom);
ALTER TABLE places ADD coord_lon_l93 double precision;
ALTER TABLE places ADD coord_lat_l93 double precision;
UPDATE places SET coord_lon_l93 = ST_X(the_geom);
UPDATE places SET coord_lat_l93 = ST_Y(the_geom);

--
-- Create indexes on observations table
--
-- Primary key
ALTER TABLE observations DROP CONSTRAINT IF EXISTS pk_observations;
ALTER TABLE observations ADD CONSTRAINT pk_observations PRIMARY KEY(id_sighting);

-- Index: public.idx_the_geom_gist
DROP INDEX IF EXISTS public.idx_observations_geom_gist;
ALTER TABLE observations DROP COLUMN IF EXISTS the_geom CASCADE;
ALTER TABLE observations DROP COLUMN IF EXISTS coord_lon_l93 CASCADE;
ALTER TABLE observations DROP COLUMN IF EXISTS coord_lat_l93 CASCADE;
SELECT AddGeometryColumn('public', 'observations', 'the_geom', 2154, 'POINT', 2);
UPDATE observations SET the_geom = ST_Transform(ST_SetSRID(ST_MakePoint(observer_coord_lon, observer_coord_lat), 4326), 2154);
CREATE INDEX idx_observations_geom_gist ON observations USING GIST (the_geom);
ALTER TABLE observations ADD coord_lon_l93 double precision;
ALTER TABLE observations ADD coord_lat_l93 double precision;
UPDATE observations SET coord_lon_l93 = ST_X(the_geom);
UPDATE observations SET coord_lat_l93 = ST_Y(the_geom);

-- -- Index: public.idx_altitude
-- -- DROP INDEX public.idx_altitude;
-- CREATE INDEX idx_altitude
  -- ON public.observations
  -- USING btree (altitude);

-- -- Index: public.idx_atlas_code
-- -- DROP INDEX public.idx_atlas_code;
-- CREATE INDEX idx_atlas_code
  -- ON public.observations
  -- USING btree (atlas_code);

-- -- Index: public.idx_date
-- -- DROP INDEX public.idx_date;
-- CREATE INDEX idx_date
  -- ON public.observations
  -- USING btree (date);

-- -- Index: public.idx_date_year
-- -- DROP INDEX public.idx_date_year;
-- -- CREATE INDEX idx_date_year
  -- -- ON public.observations
  -- -- USING btree (date_year);

-- -- Index: public.idx_latin_species
-- -- DROP INDEX public.idx_latin_species;
-- CREATE INDEX idx_latin_species
  -- ON public.observations
  -- USING btree (latin_species COLLATE pg_catalog."default" varchar_pattern_ops);

-- -- Index: public.idx_municipality
-- -- DROP INDEX public.idx_municipality;
-- CREATE INDEX idx_municipality
  -- ON public.observations
  -- USING btree (municipality COLLATE pg_catalog."default" varchar_pattern_ops);

-- -- Index: public.idx_insee
-- -- DROP INDEX public.idx_insee;
-- CREATE INDEX idx_insee
  -- ON public.observations
  -- USING btree (insee COLLATE pg_catalog."default");

-- -- Index: public.idx_name_species
-- -- DROP INDEX public.idx_name_species;
-- CREATE INDEX idx_name_species
  -- ON public.observations
  -- USING btree (name_species COLLATE pg_catalog."default" varchar_pattern_ops);

-- -- Index: public.idx_comment
-- -- DROP INDEX public.idx_comment;
-- CREATE INDEX idx_comment
  -- ON public.observations
  -- USING btree (comment COLLATE pg_catalog."default" varchar_pattern_ops);

-- -- Index: public.idx_entity_short_name
-- -- DROP INDEX public.idx_entity_short_name
-- CREATE INDEX idx_entity
  -- ON public.observations
  -- USING btree (entity COLLATE pg_catalog."default");

-- -- Index: public.idx_has_death_info
-- -- DROP INDEX public.idx_has_death_info;
-- -- CREATE INDEX idx_has_death_info
  -- -- ON public.observations
  -- -- USING btree (has_death_info);

-- -- Index: public.idx_name
-- -- DROP INDEX public.idx_name;
-- CREATE INDEX idx_name
  -- ON public.observations
  -- USING btree (name);
