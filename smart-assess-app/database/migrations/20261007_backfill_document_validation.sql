-- Found during the post-migration integration audit: the 2026-10-07 RBAC/
-- tracking migration wired document_validation into the submission code
-- path going forward, but never backfilled it for request_documents rows
-- that already existed (the seeded demo requests) — leaving them with no
-- validation record at all. Purely additive; idempotent via NOT EXISTS,
-- safe to run more than once; does not touch request_documents or any
-- other table.

INSERT INTO document_validation (request_document_id, validation_status, remarks, validated_at)
SELECT id,
  CASE file_status WHEN 'ok' THEN 'valid' WHEN 'flagged' THEN 'invalid' ELSE 'pending' END,
  CASE file_status
    WHEN 'ok' THEN 'Passed automatic format/size check.'
    WHEN 'flagged' THEN 'Uploaded file failed the automatic format/size check.'
    ELSE 'No file uploaded for this requirement.'
  END,
  NOW()
FROM request_documents rd
WHERE NOT EXISTS (SELECT 1 FROM document_validation dv WHERE dv.request_document_id = rd.id);
