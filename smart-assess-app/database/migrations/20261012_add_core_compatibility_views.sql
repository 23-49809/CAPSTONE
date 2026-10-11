-- Read-only views exposing the requested core entity names while preserving
-- the existing application tables, IDs, status history, and authentication.
CREATE OR REPLACE VIEW document_requests AS
SELECT
    r.id AS request_id,
    r.reference_no AS reference_number,
    r.client_id AS user_id,
    r.arp_number,
    r.property_address,
    r.barangay,
    r.document_type,
    NULL AS improvement_status,
    NULL AS property_holdings,
    NULL AS liens_encumbrances,
    CASE LOWER(REPLACE(r.purpose, ' ', '_'))
        WHEN 'personal_copy' THEN 'personal_copy'
        WHEN 'for_transfer' THEN 'for_transfer'
        WHEN 'for_titling' THEN 'for_titling'
        WHEN 'building_permit' THEN 'building_permit'
        WHEN 'reclassification' THEN 'reclassification'
        ELSE NULL
    END AS purpose,
    r.purpose AS source_purpose,
    CASE r.status
        WHEN 'Received' THEN 'submitted'
        WHEN 'Processing' THEN 'processing'
        WHEN 'Approved' THEN 'approved'
        WHEN 'Rejected' THEN 'rejected'
        WHEN 'Out for Release' THEN 'completed'
        WHEN 'Timed Out' THEN 'under_review'
        ELSE 'under_review'
    END AS status,
    r.status AS source_status,
    r.created_at AS submitted_at,
    r.created_at,
    r.created_at AS updated_at
FROM requests r
WHERE r.flow = 'docreq';

CREATE OR REPLACE VIEW land_transfers AS
SELECT
    r.id AS transfer_id,
    r.reference_no AS reference_number,
    r.client_id AS user_id,
    r.arp_number,
    r.property_address,
    r.barangay,
    CASE LOWER(r.transfer_type)
        WHEN 'sale' THEN 'sale'
        WHEN 'donation' THEN 'donation'
        WHEN 'estate' THEN 'inheritance'
        WHEN 'inheritance' THEN 'inheritance'
        ELSE NULL
    END AS transfer_type,
    r.transfer_type AS source_transfer_type,
    CASE r.status
        WHEN 'Received' THEN 'submitted'
        WHEN 'Processing' THEN 'processing'
        WHEN 'Approved' THEN 'approved'
        WHEN 'Rejected' THEN 'rejected'
        WHEN 'Out for Release' THEN 'completed'
        WHEN 'Timed Out' THEN 'under_review'
        ELSE 'under_review'
    END AS status,
    r.status AS source_status,
    r.created_at AS submitted_at,
    r.created_at,
    r.created_at AS updated_at
FROM requests r
WHERE r.flow = 'landtransfer';

CREATE OR REPLACE VIEW uploaded_documents AS
SELECT
    rd.id AS document_id,
    CASE WHEN r.flow = 'docreq' THEN r.id ELSE NULL END AS request_id,
    CASE WHEN r.flow = 'landtransfer' THEN r.id ELSE NULL END AS transfer_id,
    r.client_id AS user_id,
    rd.doc_key AS document_type,
    rd.label AS document_label,
    rd.original_name AS file_name,
    rd.stored_path AS file_path,
    rd.mime_type AS file_type,
    rd.file_size,
    rd.file_status,
    r.created_at AS uploaded_at
FROM request_documents rd
JOIN requests r ON r.id = rd.request_id
WHERE rd.original_name IS NOT NULL;

CREATE OR REPLACE VIEW request_status_history AS
SELECT
    l.id AS history_id,
    CASE WHEN r.flow = 'docreq' THEN r.id ELSE NULL END AS request_id,
    CASE WHEN r.flow = 'landtransfer' THEN r.id ELSE NULL END AS transfer_id,
    l.status,
    l.sms_body AS remarks,
    NULL AS changed_by,
    l.actor,
    l.created_at AS changed_at
FROM request_status_log l
JOIN requests r ON r.id = l.request_id;

CREATE OR REPLACE VIEW audit_logs AS
SELECT
    a.id AS audit_id,
    a.actor_id AS user_id,
    a.action,
    NULL AS table_name,
    a.target AS record_id,
    NULL AS old_values,
    NULL AS new_values,
    NULL AS ip_address,
    a.created_at,
    a.actor_type,
    a.actor_name
FROM audit_log a;

CREATE OR REPLACE VIEW all_user_accounts AS
SELECT
    c.id AS user_id,
    c.first_name,
    NULL AS middle_name,
    c.last_name,
    c.email,
    c.contact_number,
    NULL AS address,
    'client' AS role,
    LOWER(c.status) AS status,
    c.created_at,
    c.created_at AS updated_at,
    'client' AS account_type
FROM clients c
UNION ALL
SELECT
    u.id AS user_id,
    u.name AS first_name,
    NULL AS middle_name,
    NULL AS last_name,
    u.email,
    u.contact_number,
    NULL AS address,
    LOWER(r.name) AS role,
    LOWER(u.status) AS status,
    u.created_at,
    u.created_at AS updated_at,
    'internal' AS account_type
FROM users u
JOIN roles r ON r.id = u.role_id;
