<?php

namespace Tests\Unit;

use App\Services\ChangeDetector;
use PHPUnit\Framework\TestCase;

class ChangeDetectorTest extends TestCase
{
    private ChangeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new ChangeDetector;
    }

    public function test_identical_text_is_unchanged(): void
    {
        $result = $this->detector->detect('respuesta', 'respuesta');
        $this->assertEquals('unchanged', $result['type']);
        $this->assertEquals(1.0, $result['similarity']);
    }

    public function test_completely_different_text_is_new_version(): void
    {
        $result = $this->detector->detect('la capital de Francia es París', 'el color del cielo es azul');
        $this->assertEquals('new_version', $result['type']);
    }

    public function test_minor_change_detected(): void
    {
        $result = $this->detector->detect('París es la capital de Francia', 'París es la capital famosa de Francia');
        $this->assertEquals('minor', $result['type']);
    }

    public function test_hash_matches_text(): void
    {
        $hash = hash('sha256', 'test');
        $this->assertEquals($hash, $this->detector->hash('test'));
    }
}
