<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Collection;
use App\Models\PetType;
use Illuminate\Database\Seeder;

/**
 * Editable taxonomy.
 *
 * Dogs and cats lead because they are the primary categories, but the
 * taxonomy is ordinary data: the owner can add rabbits, birds or anything
 * else without a code change. No live animals and no medical products.
 */
class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $petTypes = [
            ['dogs', 'Dogs', 'Everyday kit for walks, meals and rest', 1, true],
            ['cats', 'Cats', 'Scratching, climbing, feeding and quiet corners', 2, true],
            ['small-pets', 'Small pets', 'Rabbits, guinea pigs and other small companions', 3, false],
        ];

        foreach ($petTypes as [$slug, $name, $tagline, $position, $primary]) {
            // Locally drawn SVG placeholders. Replace with real photography
            // before launch; see docs/live-launch-checklist.md.
            $image = in_array($slug, ['dogs', 'cats'], true) ? "demo/pet-{$slug}.svg" : null;

            PetType::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'tagline' => $tagline,
                'image_path' => $image,
                'position' => $position,
                'is_primary' => $primary,
                // Non-primary types start hidden so the shop does not show
                // an empty category on day one.
                'is_active' => $primary,
            ]);
        }

        $categories = [
            ['toys', 'Toys', 'Fetch, tug, chew and solo play.', 1],
            ['enrichment', 'Enrichment', 'Puzzles and slow feeders that keep a busy mind occupied.', 2],
            ['feeding', 'Feeding', 'Bowls, mats and storage.', 3],
            ['grooming', 'Grooming', 'Brushes, combs and nail care.', 4],
            ['travel', 'Travel', 'Harnesses, carriers and water on the move.', 5],
            ['beds', 'Beds', 'Somewhere soft to land.', 6],
        ];

        $dogs = PetType::where('slug', 'dogs')->first();
        $cats = PetType::where('slug', 'cats')->first();

        foreach ($categories as [$slug, $name, $description, $position]) {
            $category = Category::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'position' => $position,
                'is_active' => true,
            ]);

            $category->petTypes()->syncWithoutDetaching(array_filter([$dogs?->id, $cats?->id]));
        }

        $attributes = [
            ['size', 'Size', 'select', null, true, true, 1, ['Small', 'Medium', 'Large', 'Extra large']],
            ['colour', 'Colour', 'select', null, true, true, 2, ['Sage', 'Charcoal', 'Sand', 'Clay']],
            ['material', 'Material', 'select', null, true, false, 3, ['Cotton', 'Recycled polyester', 'Stainless steel', 'Silicone', 'Sisal']],
            ['machine-washable', 'Machine washable', 'boolean', null, true, false, 4, ['Yes', 'No']],
        ];

        foreach ($attributes as [$code, $name, $type, $unit, $filterable, $isOption, $position, $values]) {
            $attribute = Attribute::updateOrCreate(['code' => $code], [
                'name' => $name,
                'type' => $type,
                'unit' => $unit,
                'is_filterable' => $filterable,
                'is_variant_option' => $isOption,
                'position' => $position,
            ]);

            foreach ($values as $index => $value) {
                AttributeValue::updateOrCreate(
                    ['attribute_id' => $attribute->id, 'value' => $value],
                    ['label' => $value, 'position' => $index]
                );
            }
        }

        $collections = [
            ['new-arrivals', 'New arrivals', 'Just landed in our US warehouses', 1, true],
            ['everyday-essentials', 'Everyday essentials', 'The things you actually reach for', 2, true],
            ['quiet-corners', 'Quiet corners', 'Beds and hideaways for proper rest', 3, false],
        ];

        foreach ($collections as [$slug, $title, $subtitle, $position, $featured]) {
            Collection::updateOrCreate(['slug' => $slug], [
                'title' => $title,
                'subtitle' => $subtitle,
                'position' => $position,
                'is_featured' => $featured,
                'is_active' => true,
            ]);
        }
    }
}
