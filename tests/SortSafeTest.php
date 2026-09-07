<?php

namespace Zerp\RealEstate\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * The index screens sort by a column and direction taken from the query string.
 * Eloquent binds values but not identifiers, so an unchecked ?sort= is
 * interpolated straight into the SQL. See zerp-pk/zerp#39.
 *
 * These assert the macro the controllers call, against a real sqlite table.
 */
class SortSafeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sortables', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    private function query()
    {
        return (new class extends Model
        {
            protected $table = 'sortables';
        })->newQuery();
    }

    public function test_an_injected_sort_column_is_discarded(): void
    {
        $sql = $this->query()
            ->sortSafe('name asc, (select sqlite_version()) --', null, 'created_at', 'desc')
            ->toSql();

        $this->assertStringNotContainsString('sqlite_version', $sql);
        $this->assertStringNotContainsString('--', $sql);
        $this->assertStringContainsString('order by "created_at" desc', $sql);
    }

    public function test_an_injected_direction_is_discarded(): void
    {
        $sql = $this->query()
            ->sortSafe('name', 'asc; drop table sortables', 'created_at', 'desc')
            ->toSql();

        $this->assertStringNotContainsString('drop table', $sql);
        $this->assertStringContainsString('order by "name" desc', $sql);
    }

    public function test_a_real_column_still_sorts(): void
    {
        $sql = $this->query()->sortSafe('name', 'asc', 'created_at', 'desc')->toSql();

        $this->assertStringContainsString('order by "name" asc', $sql);
    }

    public function test_an_unknown_column_falls_back_to_the_default(): void
    {
        $sql = $this->query()->sortSafe('not_a_column', 'asc', 'created_at', 'desc')->toSql();

        $this->assertStringContainsString('order by "created_at" asc', $sql);
    }
}
