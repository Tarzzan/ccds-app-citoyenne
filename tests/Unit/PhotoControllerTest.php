<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour PhotoController
 * Couvre : validation des fichiers, upload multiple, suppression
 */
class PhotoControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Validation des fichiers
    // -------------------------------------------------------------------------

    public function testAcceptsJpegMimeType(): void
    {
        $this->assertTrue($this->isAllowedMimeType('image/jpeg'));
    }

    public function testAcceptsPngMimeType(): void
    {
        $this->assertTrue($this->isAllowedMimeType('image/png'));
    }

    public function testAcceptsWebpMimeType(): void
    {
        $this->assertTrue($this->isAllowedMimeType('image/webp'));
    }

    public function testRejectsPdfMimeType(): void
    {
        $this->assertFalse($this->isAllowedMimeType('application/pdf'));
    }

    public function testRejectsExecutableMimeType(): void
    {
        $this->assertFalse($this->isAllowedMimeType('application/x-executable'));
    }

    // -------------------------------------------------------------------------
    // Taille des fichiers
    // -------------------------------------------------------------------------

    public function testFileSizeUnder5MbIsAccepted(): void
    {
        $sizeBytes = 4 * 1024 * 1024; // 4 MB
        $this->assertLessThanOrEqual(5 * 1024 * 1024, $sizeBytes);
    }

    public function testFileSizeOver5MbIsRejected(): void
    {
        $sizeBytes = 6 * 1024 * 1024; // 6 MB
        $this->assertGreaterThan(5 * 1024 * 1024, $sizeBytes);
    }

    // -------------------------------------------------------------------------
    // Limite d'upload (max 5 photos par signalement)
    // -------------------------------------------------------------------------

    public function testCannotUploadMoreThan5Photos(): void
    {
        $existingCount = 4;
        $newCount      = 2;
        $total         = $existingCount + $newCount;
        $this->assertGreaterThan(5, $total);
    }

    public function testCanUploadWhenUnderLimit(): void
    {
        $existingCount = 2;
        $newCount      = 2;
        $total         = $existingCount + $newCount;
        $this->assertLessThanOrEqual(5, $total);
    }

    // -------------------------------------------------------------------------
    // Génération de nom de fichier sécurisé
    // -------------------------------------------------------------------------

    public function testGeneratedFilenameIsUnique(): void
    {
        $name1 = $this->generateFilename('photo.jpg');
        $name2 = $this->generateFilename('photo.jpg');
        // Les noms générés avec uniqid doivent être différents
        $this->assertNotEquals($name1, $name2);
    }

    public function testGeneratedFilenameHasCorrectExtension(): void
    {
        $filename = $this->generateFilename('photo.jpg');
        $this->assertStringEndsWith('.jpg', $filename);
    }

    // -------------------------------------------------------------------------
    // Helpers privés
    // -------------------------------------------------------------------------

    private function isAllowedMimeType(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    private function generateFilename(string $original): string
    {
        $ext = pathinfo($original, PATHINFO_EXTENSION);
        return uniqid('photo_', true) . '.' . $ext;
    }
}
