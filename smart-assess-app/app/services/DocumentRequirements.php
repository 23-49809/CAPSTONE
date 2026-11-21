<?php
declare(strict_types=1);

require_once __DIR__ . '/IdDocumentTypes.php';
require_once __DIR__ . '/DocumentAnalysis.php';

final class DocumentRequirements
{
    public const STATUS_MISSING = 'missing';
    public const STATUS_UPLOADED_VALID_FORMAT = 'uploaded_valid_format';
    public const STATUS_UPLOADED_INVALID_FORMAT = 'uploaded_invalid_format';

    /**
     * Every uploaded file must be strictly smaller than this (5 MiB), the same
     * limit OCR applies, so an accepted upload can always be read.
     */
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    /** Different documents one Document Request application may ask for (each its own request). */
    public const MAX_DOCUMENTS_PER_APPLICATION = 5;
    /** IDs (front and back) and the authorization letter: photos or scans as JPG/PNG only (as the public form says). */
    public const IDENTITY_KEYS = ['ownerId', 'requesterId', 'authLetter', 'ownerIdBack', 'requesterIdBack'];
    /**
     * The back side of each ID, stored as its own file: front key => back key. A back side is kept
     * with the request for Staff to inspect; it is never sent to the automatic document check
     * (AiVerification::jobManifest() skips it), so the check reads the front exactly as before.
     */
    public const ID_BACK_KEYS = ['ownerId' => 'ownerIdBack', 'requesterId' => 'requesterIdBack'];
    /** Form fields for the applicant-selected type of each ID: front key => submission field. */
    public const ID_TYPE_FIELDS = ['ownerId' => 'owner_id_type', 'requesterId' => 'requester_id_type'];
    private const IDENTITY_MIME_TYPES = [
        'image/jpeg',
        'image/png',
    ];
    private const SUPPORTING_DOCUMENT_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    private const LAND_TRANSFER_REQUIREMENTS = [
        'ctcTdOrTitle' => 'Certified True Copy of Tax Declaration or Title',
        'notarialDeed' => 'Notarial Deed of Sale or Donation',
        'vicinityMap' => 'Vicinity Map',
        'certNoImprovement' => 'Certification of No Improvement',
        'taxClearance' => 'Tax Clearance',
    ];

    /**
     * @return array{
     *     requestType: string,
     *     submissionType: string,
     *     complete: bool,
     *     items: list<array{key: string, label: string, status: string}>,
     *     missing: list<string>,
     *     invalidFormat: list<string>,
     *     validationScope: string
     * }
     */
    /**
     * The server's list of documents a request needs, key => label. This is
     * the only source of truth: submissions never decide it.
     *
     * @return array<string,string>
     */
    public static function requirementsFor(string $requestType, bool $isOwner, ?array $idTypes = null): array
    {
        if (!in_array($requestType, ['docreq', 'landtransfer'], true)) {
            throw new InvalidArgumentException('Unsupported request type.');
        }
        $requirements = $requestType === 'landtransfer' ? self::LAND_TRANSFER_REQUIREMENTS : [];
        $requirements['ownerId'] = $requestType === 'landtransfer' ? 'Valid ID of Property Owner' : 'Valid Government-Issued ID';
        if (!$isOwner) {
            $requirements['requesterId'] = 'Valid ID of Requester';
            $requirements['authLetter'] = 'Authorization Letter';
        }
        // Back sides are part of a new submission only ($idTypes given). Every ID needs both sides,
        // whatever type the applicant selected: the selection is an unverified hint and never decides
        // what is required. Without $idTypes (the automatic check, older callers) the list is the
        // front-only one as before.
        if ($idTypes !== null) {
            foreach (self::ID_BACK_KEYS as $front => $back) {
                if (isset($requirements[$front])) {
                    $requirements[$back] = self::backLabel($requirements[$front]);
                }
            }
        }
        return $requirements;
    }

    public static function backLabel(string $frontLabel): string
    {
        return $frontLabel . ' (back side)';
    }

    public static function isIdBackKey(string $key): bool
    {
        return in_array($key, self::ID_BACK_KEYS, true);
    }

    /**
     * The Staff-facing label of a stored applicant-selected ID type, or null for none or for a value
     * that is not one of the registry's types (an old, tampered or unknown value is never shown).
     */
    public static function idTypeHintLabel(mixed $code): ?string
    {
        return is_string($code) ? (self::idTypeOptions()[$code] ?? null) : null;
    }

    /**
     * The ID types an applicant may select, code => label: exactly the government IDs the
     * document-recognition registry recognises by their printed title (IdDocumentTypes::PRECEDENCE),
     * labelled and ordered as DocumentAnalysis::ID_KINDS. A school, company or "other" ID is not
     * offered. The selection is only the applicant's statement: it never confirms the document's type.
     *
     * @return array<string,string>
     */
    public static function idTypeOptions(): array
    {
        return array_filter(DocumentAnalysis::ID_KINDS, static fn (string $code): bool => in_array($code, IdDocumentTypes::PRECEDENCE, true), ARRAY_FILTER_USE_KEY);
    }

    /** Every document key any request can have. */
    public static function allKeys(): array
    {
        return array_merge(array_keys(self::LAND_TRANSFER_REQUIREMENTS), self::IDENTITY_KEYS);
    }

    /** @return list<string> MIME types accepted for a document key ([] for an unknown key). */
    public static function allowedMimeTypes(string $key): array
    {
        if (in_array($key, self::IDENTITY_KEYS, true)) {
            return self::IDENTITY_MIME_TYPES;
        }
        return isset(self::LAND_TRANSFER_REQUIREMENTS[$key]) ? self::SUPPORTING_DOCUMENT_MIME_TYPES : [];
    }

    public static function acceptsSize(int $bytes): bool
    {
        return $bytes > 0 && $bytes < self::MAX_FILE_BYTES;
    }

    public function evaluate(mixed $requestType, mixed $isOwner, mixed $uploadedFiles): array
    {
        if (!is_string($requestType) || !in_array($requestType, ['docreq', 'landtransfer'], true)) {
            throw new InvalidArgumentException('Unsupported request type.');
        }
        if (!is_bool($isOwner)) {
            throw new InvalidArgumentException('Submission ownership must be a boolean.');
        }
        if (!is_array($uploadedFiles)) {
            throw new InvalidArgumentException('Uploaded-file metadata must be an array.');
        }

        $this->validateUploadedFiles($uploadedFiles);

        $requirements = self::requirementsFor($requestType, $isOwner);

        $items = [];
        $missing = [];
        $invalidFormat = [];
        foreach ($requirements as $key => $label) {
            $status = $this->classifyFile($key, $uploadedFiles[$key] ?? null);
            $items[] = ['key' => $key, 'label' => $label, 'status' => $status];
            if ($status === self::STATUS_MISSING) {
                $missing[] = $key;
            } elseif ($status === self::STATUS_UPLOADED_INVALID_FORMAT) {
                $invalidFormat[] = $key;
            }
        }

        return [
            'requestType' => $requestType,
            'submissionType' => $isOwner ? 'owner' : 'representative',
            'complete' => $missing === [] && $invalidFormat === [],
            'items' => $items,
            'missing' => $missing,
            'invalidFormat' => $invalidFormat,
            'validationScope' => 'File format and size only; document content and authenticity are not assessed.',
        ];
    }

    private function validateUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $key => $metadata) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Uploaded-file keys must be strings.');
            }
            if ($metadata === null) {
                continue;
            }
            if (!is_array($metadata) ||
                !array_key_exists('mime', $metadata) ||
                !array_key_exists('size', $metadata) ||
                !is_string($metadata['mime']) ||
                !is_int($metadata['size'])) {
                throw new InvalidArgumentException('Uploaded-file metadata must contain a MIME type and integer size.');
            }
        }
    }

    private function classifyFile(string $key, ?array $metadata): string
    {
        if ($metadata === null) {
            return self::STATUS_MISSING;
        }

        $mime = strtolower($metadata['mime']);
        if (in_array($mime, self::allowedMimeTypes($key), true) && self::acceptsSize($metadata['size'])) {
            return self::STATUS_UPLOADED_VALID_FORMAT;
        }

        return self::STATUS_UPLOADED_INVALID_FORMAT;
    }
}
