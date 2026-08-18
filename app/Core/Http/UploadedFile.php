<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Support\Str;
use App\Exceptions\ValidationException;

/**
 * A single uploaded file.
 *
 * Validation is deliberately strict: the MIME type is derived from the file
 * contents rather than trusting the browser, and stored filenames are always
 * regenerated so that a crafted name can never traverse the filesystem.
 *
 * @package App\Core\Http
 * @version 1.0.0
 */
final class UploadedFile
{
    public function __construct(
        private readonly string $originalName,
        private readonly string $temporaryPath,
        private readonly int $size,
        private readonly int $error
    ) {
    }

    /**
     * Build the file map from $_FILES, ignoring multi-file inputs the system
     * does not use.
     *
     * @param array<string,mixed> $files
     *
     * @return array<string,self>
     */
    public static function fromGlobals(array $files): array
    {
        $result = [];

        foreach ($files as $key => $file) {
            if (!is_array($file) || !isset($file['tmp_name']) || is_array($file['tmp_name'])) {
                continue;
            }

            $result[(string) $key] = new self(
                (string) ($file['name'] ?? ''),
                (string) $file['tmp_name'],
                (int) ($file['size'] ?? 0),
                (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            );
        }

        return $result;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && $this->temporaryPath !== ''
            && is_uploaded_file($this->temporaryPath);
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function error(): int
    {
        return $this->error;
    }

    /**
     * The lower-case extension taken from the original filename.
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * The MIME type detected from the file contents.
     */
    public function mimeType(): string
    {
        if (!is_readable($this->temporaryPath)) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $this->temporaryPath);
        finfo_close($finfo);

        return $mime === false ? 'application/octet-stream' : $mime;
    }

    /**
     * Human-readable description of a PHP upload error code.
     */
    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK         => '',
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the maximum permitted size.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension stopped the upload.',
            default               => 'The file could not be uploaded.',
        };
    }

    /**
     * Validate the upload against a size limit and an allowed MIME list.
     *
     * @param list<string> $allowedMimeTypes
     *
     * @throws ValidationException
     */
    public function validate(string $field, int $maxBytes, array $allowedMimeTypes): void
    {
        $errors = [];

        if (!$this->isValid()) {
            $errors[] = $this->errorMessage() ?: 'The uploaded file is not valid.';
        } else {
            if ($this->size > $maxBytes) {
                $errors[] = sprintf(
                    'The file exceeds the maximum size of %s.',
                    Str::bytes($maxBytes)
                );
            }

            $mime = $this->mimeType();
            if ($allowedMimeTypes !== [] && !in_array($mime, $allowedMimeTypes, true)) {
                $errors[] = 'The file type is not permitted.';
            }

            // An image must additionally survive a real image parse, which
            // rejects polyglot files carrying script payloads.
            if (str_starts_with($mime, 'image/') && @getimagesize($this->temporaryPath) === false) {
                $errors[] = 'The image file is corrupt or not a real image.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException([$field => $errors]);
        }
    }

    /**
     * Move the upload into the destination directory under a generated name.
     *
     * @return string The stored filename (never a client-supplied value).
     *
     * @throws ValidationException When the file cannot be stored.
     */
    public function store(string $destinationDirectory, ?string $prefix = null): string
    {
        if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0o750, true) && !is_dir($destinationDirectory)) {
            throw new ValidationException(['file' => ['The upload destination is not writable.']]);
        }

        $extension = $this->safeExtension();
        $filename  = ($prefix !== null ? Str::slug($prefix) . '-' : '') . Str::randomHex(16) . $extension;
        $target    = rtrim($destinationDirectory, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($this->temporaryPath, $target)) {
            throw new ValidationException(['file' => ['The uploaded file could not be stored.']]);
        }

        chmod($target, 0o640);

        return $filename;
    }

    /**
     * Derive a safe extension from the detected MIME type, never from the
     * client-supplied filename.
     */
    private function safeExtension(): string
    {
        return match ($this->mimeType()) {
            'image/jpeg'      => '.jpg',
            'image/png'       => '.png',
            'image/webp'      => '.webp',
            'application/pdf' => '.pdf',
            default           => '.bin',
        };
    }
}
