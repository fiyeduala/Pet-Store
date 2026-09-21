<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Adapters\Demo;

use App\Domain\Supplier\DTO\WarehouseStockReading;

/**
 * Fixed demo catalogue.
 *
 * Deliberately includes variants whose stock is genuinely unknown and
 * variants stocked in more than one warehouse, so split shipments and the
 * "we don't know" stock path are both reachable without a live account.
 */
final class DemoCatalogue
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function products(): array
    {
        return [
            [
                'pid' => 'DEMO-P-1001',
                'name' => 'Braided Rope Tug Toy',
                'description' => 'Cotton rope tug toy with knotted ends, suitable for medium and large dogs.',
                'category' => 'Dog Toys',
                'images' => ['demo/rope-tug-1.svg', 'demo/rope-tug-2.svg'],
                'variants' => [
                    ['vid' => 'DEMO-V-1001-S', 'sku' => 'DEMO-ROPE-S', 'name' => 'Small', 'cost_minor' => 310, 'options' => ['Size' => 'Small'], 'weight_grams' => 180, 'length_mm' => 280, 'width_mm' => 60, 'height_mm' => 60, 'images' => []],
                    ['vid' => 'DEMO-V-1001-L', 'sku' => 'DEMO-ROPE-L', 'name' => 'Large', 'cost_minor' => 480, 'options' => ['Size' => 'Large'], 'weight_grams' => 340, 'length_mm' => 420, 'width_mm' => 80, 'height_mm' => 80, 'images' => []],
                ],
            ],
            [
                'pid' => 'DEMO-P-1002',
                'name' => 'Slow Feeder Bowl',
                'description' => 'Moulded maze bowl that slows eating. Dishwasher safe, non-slip base.',
                'category' => 'Feeding',
                'images' => ['demo/slow-feeder-1.svg'],
                'variants' => [
                    ['vid' => 'DEMO-V-1002-SG', 'sku' => 'DEMO-FEED-SG', 'name' => 'Sage', 'cost_minor' => 640, 'options' => ['Colour' => 'Sage'], 'weight_grams' => 420, 'length_mm' => 240, 'width_mm' => 240, 'height_mm' => 60, 'images' => []],
                    ['vid' => 'DEMO-V-1002-CH', 'sku' => 'DEMO-FEED-CH', 'name' => 'Charcoal', 'cost_minor' => 640, 'options' => ['Colour' => 'Charcoal'], 'weight_grams' => 420, 'length_mm' => 240, 'width_mm' => 240, 'height_mm' => 60, 'images' => []],
                ],
            ],
            [
                'pid' => 'DEMO-P-1003',
                'name' => 'Corduroy Bolster Bed',
                'description' => 'Bolstered rectangular bed with a removable, washable cover.',
                'category' => 'Beds',
                'images' => ['demo/bolster-bed-1.svg', 'demo/bolster-bed-2.svg'],
                'variants' => [
                    ['vid' => 'DEMO-V-1003-M', 'sku' => 'DEMO-BED-M', 'name' => 'Medium', 'cost_minor' => 2140, 'options' => ['Size' => 'Medium'], 'weight_grams' => 1800, 'length_mm' => 700, 'width_mm' => 550, 'height_mm' => 180, 'images' => []],
                    ['vid' => 'DEMO-V-1003-L', 'sku' => 'DEMO-BED-L', 'name' => 'Large', 'cost_minor' => 2980, 'options' => ['Size' => 'Large'], 'weight_grams' => 2600, 'length_mm' => 900, 'width_mm' => 650, 'height_mm' => 200, 'images' => []],
                ],
            ],
            [
                'pid' => 'DEMO-P-1004',
                'name' => 'Sisal Cat Scratching Post',
                'description' => 'Natural sisal post on a weighted base with a top perch.',
                'category' => 'Cat Enrichment',
                'images' => ['demo/scratch-post-1.svg'],
                'variants' => [
                    ['vid' => 'DEMO-V-1004-STD', 'sku' => 'DEMO-SCRATCH-STD', 'name' => 'Standard', 'cost_minor' => 1720, 'options' => [], 'weight_grams' => 2400, 'length_mm' => 400, 'width_mm' => 400, 'height_mm' => 620, 'images' => []],
                ],
            ],
            [
                'pid' => 'DEMO-P-1005',
                'name' => 'Deshedding Grooming Brush',
                'description' => 'Stainless steel deshedding edge with a moulded grip and release button.',
                'category' => 'Grooming',
                'images' => ['demo/grooming-brush-1.svg'],
                'variants' => [
                    ['vid' => 'DEMO-V-1005-CAT', 'sku' => 'DEMO-BRUSH-CAT', 'name' => 'Cat', 'cost_minor' => 520, 'options' => ['For' => 'Cats'], 'weight_grams' => 160, 'length_mm' => 180, 'width_mm' => 70, 'height_mm' => 40, 'images' => []],
                    ['vid' => 'DEMO-V-1005-DOG', 'sku' => 'DEMO-BRUSH-DOG', 'name' => 'Dog', 'cost_minor' => 610, 'options' => ['For' => 'Dogs'], 'weight_grams' => 210, 'length_mm' => 200, 'width_mm' => 80, 'height_mm' => 45, 'images' => []],
                ],
            ],
            [
                'pid' => 'DEMO-P-1006',
                'name' => 'Folding Travel Water Bottle',
                'description' => 'Leak-resistant bottle with a fold-out drinking trough and a carry clip.',
                'category' => 'Travel',
                'images' => ['demo/travel-bottle-1.svg'],
                'variants' => [
                    ['vid' => 'DEMO-V-1006-350', 'sku' => 'DEMO-BOTTLE-350', 'name' => '350 ml', 'cost_minor' => 430, 'options' => ['Capacity' => '350 ml'], 'weight_grams' => 190, 'length_mm' => 210, 'width_mm' => 75, 'height_mm' => 75, 'images' => []],
                    ['vid' => 'DEMO-V-1006-550', 'sku' => 'DEMO-BOTTLE-550', 'name' => '550 ml', 'cost_minor' => 560, 'options' => ['Capacity' => '550 ml'], 'weight_grams' => 240, 'length_mm' => 240, 'width_mm' => 80, 'height_mm' => 80, 'images' => []],
                ],
            ],
            [
                'pid' => 'DEMO-P-1007',
                'name' => 'Treat Dispensing Puzzle Ball',
                'description' => 'Adjustable-difficulty treat ball for slow feeding and enrichment.',
                'category' => 'Enrichment',
                'images' => ['demo/puzzle-ball-1.svg'],
                'variants' => [
                    // Stock is intentionally never reported for this variant.
                    ['vid' => 'DEMO-V-1007-UNK', 'sku' => 'DEMO-PUZZLE-UNK', 'name' => 'Standard', 'cost_minor' => 740, 'options' => [], 'weight_grams' => 260, 'length_mm' => 110, 'width_mm' => 110, 'height_mm' => 110, 'images' => []],
                ],
            ],
            [
                'pid' => 'DEMO-P-1008',
                'name' => 'Reflective Adjustable Harness',
                'description' => 'Padded step-in harness with reflective stitching and two attachment points.',
                'category' => 'Travel',
                'images' => ['demo/harness-1.svg'],
                'variants' => [
                    ['vid' => 'DEMO-V-1008-M', 'sku' => 'DEMO-HARNESS-M', 'name' => 'Medium', 'cost_minor' => 1180, 'options' => ['Size' => 'Medium'], 'weight_grams' => 300, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 40, 'images' => []],
                    ['vid' => 'DEMO-V-1008-L', 'sku' => 'DEMO-HARNESS-L', 'name' => 'Large', 'cost_minor' => 1340, 'options' => ['Size' => 'Large'], 'weight_grams' => 360, 'length_mm' => 340, 'width_mm' => 220, 'height_mm' => 45, 'images' => []],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $pid): ?array
    {
        foreach (self::products() as $product) {
            if ($product['pid'] === $pid) {
                return $product;
            }
        }

        return null;
    }

    public static function costMinorFor(string $vid): int
    {
        foreach (self::products() as $product) {
            foreach ($product['variants'] as $variant) {
                if ($variant['vid'] === $vid) {
                    return (int) $variant['cost_minor'];
                }
            }
        }

        return 0;
    }

    /**
     * Per-warehouse stock, deterministic by variant id.
     *
     * @return array<int, WarehouseStockReading>
     */
    public static function stockFor(string $vid): array
    {
        // This variant models a supplier that reports nothing at all. The
        // application must present it as "availability unknown", not zero.
        if (str_ends_with($vid, '-UNK')) {
            return [
                new WarehouseStockReading('US-NJ', 'US', null, 'Demo New Jersey', ['demo' => true, 'note' => 'no quantity reported']),
                new WarehouseStockReading('CN-GZ', 'CN', null, 'Demo Guangzhou', ['demo' => true, 'note' => 'no quantity reported']),
            ];
        }

        $seed = crc32($vid);

        $nj = $seed % 37;                 // sometimes zero, sometimes plenty
        $ca = ($seed >> 3) % 23;
        $cn = 100 + ($seed % 400);        // overseas is always well stocked

        return [
            new WarehouseStockReading('US-NJ', 'US', $nj, 'Demo New Jersey', ['demo' => true]),
            new WarehouseStockReading('US-CA', 'US', $ca, 'Demo California', ['demo' => true]),
            new WarehouseStockReading('CN-GZ', 'CN', $cn, 'Demo Guangzhou', ['demo' => true]),
        ];
    }
}
