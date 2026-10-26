-- =============================================================
-- Phase 3 migration: category names must be unique.
-- Only needed for databases created before Phase 3.
--
-- The unicode_ci collation makes this case-insensitive, so
-- "Fashion" and "fashion" count as the same name.
--
-- If this fails with "Duplicate entry", rename the duplicates
-- first. Find them with:
--   SELECT name, COUNT(*) FROM categories GROUP BY name HAVING COUNT(*) > 1;
-- =============================================================

ALTER TABLE `categories`
    ADD UNIQUE KEY `uq_categories_name` (`name`);
