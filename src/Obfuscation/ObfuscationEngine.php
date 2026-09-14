<?php

namespace Shamimstack\AssetShield\Obfuscation;

/**
 * Adapter contract for any JS obfuscator the PHP side can drive.
 *
 * Implementations MUST be honest about their guarantees: obfuscation raises
 * the effort to reverse engineer client-delivered code, it never makes it
 * un-inspectable.
 */
interface ObfuscationEngine
{
    /**
     * Whether the underlying engine can run right now (binary + package).
     */
    public function isAvailable(): bool;

    /**
     * @param array<string, mixed> $options engine-specific option map
     *
     * @throws \Shamimstack\AssetShield\Exceptions\ObfuscationException
     */
    public function obfuscate(string $source, array $options = []): string;

    /** @return list<string> known preset names */
    public function presets(): array;
}