<?php

/**
 * The Links in and Links out columns of the content lists: how many
 * published items link to each item, and how many items it links to, from
 * Teme SEO's index. An item the index hasn't read shows an empty cell.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Columns;

use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRows;
use Teme\Seo\Storage\ItemIndex;

final readonly class LinksColumn implements ListColumn
{
    public function __construct(
        private ItemIndex $index,
        private bool $incoming,
    ) {}

    /**
     * Returns each row's count by its ID.
     */
    public function cells(ListRows $rows): array
    {
        $ids = $rows->ids();

        if ($ids === []) {
            return [];
        }

        $read = $this->index->linksOut($ids);
        $counts = $this->incoming ? array_intersect_key($this->index->linksIn($ids), $read) : $read;

        return array_map(fn (int $count): Cell => Cell::number($count), $counts);
    }
}
