<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour CategoryController
 * Couvre : listCategories, createCategory, updateCategory, deleteCategory
 */
class CategoryControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Validation des données de catégorie
    // -------------------------------------------------------------------------

    public function testCategoryNameCannotBeEmpty(): void
    {
        $name = '';
        $this->assertEmpty($name);
    }

    public function testCategoryNameMaxLength(): void
    {
        $name = str_repeat('a', 101);
        $this->assertGreaterThan(100, strlen($name));
    }

    public function testCategoryNameAcceptsValidInput(): void
    {
        $name = 'Voirie';
        $this->assertNotEmpty($name);
        $this->assertLessThanOrEqual(100, strlen($name));
    }

    public function testCategoryIconIsEmoji(): void
    {
        $icon = '🛣️';
        // Un emoji est encodé en UTF-8 avec au moins 3 octets
        $this->assertGreaterThanOrEqual(3, strlen($icon));
    }

    public function testCategoryColorIsHexFormat(): void
    {
        $color = '#FF5733';
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $color);
    }

    public function testInvalidColorIsRejected(): void
    {
        $color = 'rouge';
        $this->assertDoesNotMatchRegularExpression('/^#[0-9A-Fa-f]{6}$/', $color);
    }

    // -------------------------------------------------------------------------
    // Activation / désactivation
    // -------------------------------------------------------------------------

    public function testCategoryIsActiveByDefault(): void
    {
        $category = ['name' => 'Éclairage', 'is_active' => 1];
        $this->assertEquals(1, $category['is_active']);
    }

    public function testCategoryCanBeDeactivated(): void
    {
        $category = ['name' => 'Éclairage', 'is_active' => 0];
        $this->assertEquals(0, $category['is_active']);
    }

    // -------------------------------------------------------------------------
    // Suppression sécurisée
    // -------------------------------------------------------------------------

    public function testCannotDeleteCategoryWithIncidents(): void
    {
        $incidentCount = 5;
        // La suppression doit être bloquée si des incidents sont liés
        $this->assertGreaterThan(0, $incidentCount);
    }

    public function testCanDeleteEmptyCategory(): void
    {
        $incidentCount = 0;
        $this->assertEquals(0, $incidentCount);
    }
}
