"""
AI Rule-Based Requirement Checker
----------------------------------
Pure rule-based (not ML) validation logic for SMART ASSESS, per Chapter 3.5
"AI Integration Design": predefined rules evaluate submitted documents and
valid IDs for completeness and format validity before a request is handed
to Assessor's Staff for final review. Staff always retain final approval.
"""

DOC_TYPES = [
    "Certified True Copy of Tax Declaration (CTC-TD)",
    "Certification of No/With Existing Improvement",
    "Certification of Property/No Property Holdings",
    "Certification of No Liens and Encumbrances",
    "Certification of Assessment",
]

PURPOSES = [
    "Personal Copy",
    "For Transfer",
    "For Titling",
    "For Building Permit",
    "For Reclassification",
    "Other Legal Requirement",
]

TRANSFER_TYPES = ["Sale", "Donation", "Estate"]

LAND_TRANSFER_DOCS = [
    {"key": "ctcTdOrTitle", "label": "Certified True Copy of Tax Declaration or Title"},
    {"key": "notarialDeed", "label": "Notarial Deed of Sale or Donation"},
    {"key": "vicinityMap", "label": "Vicinity Map"},
    {"key": "certNoImprovement", "label": "Certification of No Improvement"},
    {"key": "taxClearance", "label": "Tax Clearance"},
]

VALID_MIME_TYPES = {"image/jpeg", "image/png", "image/webp", "application/pdf"}
MAX_FILE_BYTES = 15 * 1024 * 1024  # 15 MB


def id_label(flow, key):
    labels = {
        "docreq": {
            "ownerId": "Valid Government-Issued ID",
            "requesterId": "Valid ID of Requester",
            "authLetter": "Authorization Letter",
        },
        "landtransfer": {
            "ownerId": "Valid ID of Property Owner",
            "requesterId": "Valid ID of Requester",
            "authLetter": "Authorization Letter",
        },
    }
    return labels.get(flow, labels["docreq"]).get(key, key)


def required_id_keys(is_owner):
    return ["ownerId"] if is_owner else ["ownerId", "requesterId", "authLetter"]


def classify_file(file_meta):
    """file_meta: dict with 'mime' and 'size', or None if not uploaded."""
    if not file_meta:
        return "missing"
    mime = (file_meta.get("mime") or "").lower()
    size = file_meta.get("size") or 0
    if mime in VALID_MIME_TYPES and 0 < size < MAX_FILE_BYTES:
        return "ok"
    return "flagged"


def build_checklist(flow, is_owner, uploaded_files):
    """
    uploaded_files: dict of {doc_key: {"mime": ..., "size": ...}} for files
    already uploaded and saved by the PHP layer (which passes back their
    metadata for this AI validation pass).
    """
    uploaded_files = uploaded_files or {}
    items = []
    if flow == "landtransfer":
        for doc in LAND_TRANSFER_DOCS:
            items.append({
                "key": doc["key"],
                "label": doc["label"],
                "status": classify_file(uploaded_files.get(doc["key"])),
            })
    for key in required_id_keys(is_owner):
        items.append({
            "key": key,
            "label": id_label(flow, key),
            "status": classify_file(uploaded_files.get(key)),
        })
    return items


def checklist_is_complete(items):
    return all(item["status"] == "ok" for item in items)


def get_advisory(flow, document_type, transfer_type, purpose):
    """Non-blocking compatibility hints, separate from hard requirements."""
    if flow == "docreq":
        if document_type == "Certification of Property/No Property Holdings" and purpose == "For Building Permit":
            return ('A holdings certificate confirms land ownership records, not structures. '
                    'For a building permit, "Certification of No/With Existing Improvement" is '
                    'usually required instead.')
        if document_type == "Certification of No Liens and Encumbrances" and purpose == "For Building Permit":
            return ('"Certification of No Liens and Encumbrances" is typically requested for loans '
                    'or financing. For a building permit, try "Certification of No/With Existing '
                    'Improvement" or "Certification of Assessment."')
    return None


def evaluate(payload):
    """
    payload: {
      "flow": "docreq" | "landtransfer",
      "document_type": str | None,
      "transfer_type": str | None,
      "purpose": str,
      "is_owner": bool,
      "uploaded_files": { key: {"mime": str, "size": int}, ... }
    }
    """
    flow = payload.get("flow")
    is_owner = bool(payload.get("is_owner", True))
    uploaded_files = payload.get("uploaded_files") or {}

    checklist = build_checklist(flow, is_owner, uploaded_files)
    complete = checklist_is_complete(checklist)
    missing = [item["label"] for item in checklist if item["status"] != "ok"]
    advisory = get_advisory(
        flow,
        payload.get("document_type"),
        payload.get("transfer_type"),
        payload.get("purpose"),
    )

    return {
        "requirement_complete": complete,
        "checklist": checklist,
        "missing": missing,
        "advisory": advisory,
    }
