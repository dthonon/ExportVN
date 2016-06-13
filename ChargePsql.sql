--
-- Create indexes on observations table
--

-- Index: public.idx_the_geom_gist
-- DROP INDEX public.idx_the_geom_gist;
SELECT AddGeometryColumn('public', 'observations', 'the_geom', 4326, 'POINT', 2);
UPDATE observations SET the_geom = ST_SetSRID(ST_MakePoint(observer_coord_lon, observer_coord_lat), 4326);
CREATE INDEX idx_the_geom_gist ON observations USING GIST (the_geom);

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
