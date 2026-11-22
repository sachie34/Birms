-- Add the details required by the revised MSIT SRS without rewriting existing case or queue records.
ALTER TABLE blotters
    ADD COLUMN incident_time TIME NULL AFTER incident_date;

ALTER TABLE queue_entries
    ADD COLUMN purpose VARCHAR(255) NULL AFTER reference_no;

-- Backfill queue purpose when a related request or venue reservation has a known purpose.
UPDATE queue_entries q
JOIN document_requests dr ON dr.reference_no = q.reference_no
SET q.purpose = dr.purpose
WHERE q.service = 'Documents' AND q.purpose IS NULL;

UPDATE queue_entries q
JOIN venue_reservations vr ON vr.reference_no = q.reference_no
SET q.purpose = vr.purpose
WHERE q.service = 'Venue' AND q.purpose IS NULL;
