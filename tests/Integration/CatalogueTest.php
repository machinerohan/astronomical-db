<?php

namespace Tests\Integration;

use Tests\TestCase;

class CatalogueTest extends TestCase
{
    public function test_find_object_by_name(): void
    {
        $obj = \find_object($this->pdo, 'Sirius');
        $this->assertNotNull($obj);
        $this->assertSame('Sirius', $obj['name']);
        $this->assertSame('star', $obj['entry_type']);
    }

    public function test_find_object_by_partial_name(): void
    {
        $obj = \find_object($this->pdo, 'Sir');
        $this->assertNotNull($obj);
    }

    public function test_find_object_nonexistent(): void
    {
        $obj = \find_object($this->pdo, 'ZZZNonExistent');
        $this->assertNull($obj);
    }

    public function test_find_object_with_custom_select(): void
    {
        $obj = \find_object($this->pdo, 'Sirius', 'name');
        $this->assertNotNull($obj);
        $this->assertArrayHasKey('name', $obj);
        $this->assertArrayNotHasKey('apparent_mag', $obj);
    }

    public function test_sirius_apparent_magnitude(): void
    {
        $obj = \find_object($this->pdo, 'Sirius');
        $this->assertNotNull($obj);
        $this->assertSame('-1.460', $obj['apparent_mag']);
    }

    public function test_andromeda_galaxy(): void
    {
        $obj = \find_object($this->pdo, 'Andromeda');
        $this->assertNotNull($obj);
        $this->assertSame('galaxy', $obj['entry_type']);
    }

    public function test_count_approved_for_user(): void
    {
        $count = \count_approved($this->pdo, 'proposed_entries', 'author_id', 1);
        $this->assertIsInt($count);
    }

    public function test_is_proposal_category_known(): void
    {
        $this->assertTrue(\is_proposal_category($this->pdo, 8)); // Proposals
    }

    public function test_is_proposal_category_non_proposal(): void
    {
        $this->assertFalse(\is_proposal_category($this->pdo, 1)); // General
        $this->assertFalse(\is_proposal_category($this->pdo, 3)); // Stars
    }
}
