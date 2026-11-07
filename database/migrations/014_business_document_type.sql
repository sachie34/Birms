-- 014_business_document_type.sql
-- The professor's front-counter workflow includes Business alongside the three seeded certificate types.
-- The unique document name makes this safe to apply on existing databases without duplicating the option.
INSERT INTO document_types (name, sort_order)
SELECT 'Business Clearance', 4
WHERE NOT EXISTS (
    SELECT 1 FROM document_types WHERE name = 'Business Clearance'
);
