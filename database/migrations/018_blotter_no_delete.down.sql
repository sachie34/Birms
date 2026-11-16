-- Remove only the delete guard. This does not delete or modify blotter data.
DROP TRIGGER IF EXISTS trg_blotters_no_delete;
