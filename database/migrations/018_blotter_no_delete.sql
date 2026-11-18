-- Enforce FR-B5 at the database layer: blotter records are permanent.
-- Cases must be moved through the revised status workflow and archived; they are never deleted.
CREATE TRIGGER trg_blotters_no_delete
BEFORE DELETE ON blotters
FOR EACH ROW
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Blotter records cannot be deleted; archive the case instead';
