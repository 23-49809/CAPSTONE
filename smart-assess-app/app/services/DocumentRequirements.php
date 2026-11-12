<?php
declare(strict_types=1);

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
    /** IDs and the authorization letter: photos or scans as JPG/PNG only (as the public form says). */
    public const IDENTITY_KEYS = ['ownerId', 'requesterId', 'authLetter'];
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
    public static function requirementsFor(string $requestType, bool $isOwner): array
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
        return $requirements;
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
