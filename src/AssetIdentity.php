<?php

namespace Shamimstack\AssetShield;

use Shamimstack\AssetShield\Support\MimeMapper;

/**
 * Immutable description of one registered asset.
 *
 *   logical   the Vite entry key (e.g. "resources/js/app.js")
 *   file      compiled path relative to the public root (e.g. "build/assets/app-A91Kx.js")
 *   original  the pre-mask compiled filename, when the asset was renamed
 *   opaque    the server-derived protected identifier (as_...), never stored
 *   type      script | style | image | font
 *   integrity optional SRI hash from the build manifest
 */
final class AssetIdentity
{
    public function __construct(
        private readonly string $logical,
        private readonly string $file,
        private readonly string $opaque,
        private readonly string $type,
        private readonly ?string $integrity = null,
        private readonly ?string $original = null,
    ) {
    }

    public function logical(): string
    {
        return $this->logical;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function original(): ?string
    {
        return $this->original;
    }

    public function opaque(): string
    {
        return $this->opaque;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function integrity(): ?string
    {
        return $this->integrity;
    }

    public function isStyle(): bool
    {
        return $this->type === 'style';
    }

    public function isScript(): bool
    {
        return $this->type === 'script';
    }

    /**
     * Content-Type determined from the compiled file, or null when the file is
     * not a protected asset type (the controller rejects such assets).
     */
    public function contentType(): ?string
    {
        return MimeMapper::forPath($this->file);
    }

    /**
     * @return array<string,mixed> register-friendly snapshot.
     */
    public function toArray(): array
    {
        $row = [
            'logical' => $this->logical,
            'file' => $this->file,
            'opaque' => $this->opaque,
            'type' => $this->type,
        ];

        if ($this->integrity !== null) {
            $row['integrity'] = $this->integrity;
        }

        if ($this->original !== null) {
            $row['original'] = $this->original;
        }

        return $row;
    }
}