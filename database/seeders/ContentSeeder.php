<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Collection;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Models\HomeSection;
use App\Models\NavigationItem;
use Illuminate\Database\Seeder;

/**
 * Editable storefront content.
 *
 * Policy pages are seeded as DRAFTS flagged for owner review. They contain
 * the structure a real policy needs and explicit placeholders, but no
 * invented addresses, guarantees or legal claims.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->homeSections();
        $this->navigation();
        $this->pages();
        $this->faqs();
    }

    private function homeSections(): void
    {
        $newArrivals = Collection::where('slug', 'new-arrivals')->first();

        $sections = [
            [
                'key' => 'hero',
                'type' => HomeSection::TYPE_HERO,
                'title' => 'Kit that earns its place',
                'subtitle' => 'A small, considered range for dogs and cats — chosen for how it holds up, not how it photographs.',
                'cta_label' => 'Shop everything',
                'cta_url' => '/shop',
                'position' => 1,
            ],
            [
                'key' => 'pet-entry',
                'type' => HomeSection::TYPE_PET_ENTRY,
                'title' => 'Shop by pet',
                'subtitle' => null,
                'position' => 2,
            ],
            [
                'key' => 'new-arrivals',
                'type' => HomeSection::TYPE_COLLECTION_ROW,
                'title' => 'New arrivals',
                'subtitle' => 'Recently added to our US warehouses.',
                'collection_id' => $newArrivals?->id,
                'position' => 3,
            ],
            [
                'key' => 'featured',
                'type' => HomeSection::TYPE_FEATURED_PRODUCTS,
                'title' => 'Worth a look',
                'subtitle' => null,
                'position' => 4,
            ],
            [
                'key' => 'how-we-ship',
                'type' => HomeSection::TYPE_INFO_COLUMNS,
                'title' => 'How we work',
                'position' => 5,
                'settings' => [
                    'columns' => [
                        [
                            'title' => 'Shipped from US warehouses',
                            // Carefully worded: stocked in the US is not the
                            // same as made in the US.
                            'body' => 'We prioritise stock already held in US warehouses. That is where it ships from — it is not a claim about where it was made.',
                        ],
                        [
                            'title' => 'Delivery times you can check',
                            'body' => 'Enter your ZIP code on any product page and we will show the services actually available to you, with the carrier\'s own estimate.',
                        ],
                        [
                            'title' => 'Questions answered by a person',
                            'body' => 'Email us about an order, a size or a return and you will get a real answer.',
                        ],
                    ],
                ],
            ],
        ];

        foreach ($sections as $section) {
            HomeSection::updateOrCreate(['key' => $section['key']], $section + ['is_active' => true]);
        }
    }

    private function navigation(): void
    {
        $items = [
            ['header', 'All products', '/shop', 1],
            ['footer_shop', 'All products', '/shop', 1],
            ['footer_shop', 'New arrivals', '/collections/new-arrivals', 2],
            ['footer_shop', 'Dogs', '/shop/pets/dogs', 3],
            ['footer_shop', 'Cats', '/shop/pets/cats', 4],
            ['footer_support', 'Track your order', '/orders/track', 1],
            ['footer_support', 'FAQ', '/faq', 2],
            ['footer_support', 'Shipping', '/p/shipping', 3],
            ['footer_support', 'Returns', '/p/returns', 4],
            ['footer_support', 'Contact', '/contact', 5],
            ['footer_company', 'About', '/p/about', 1],
            ['footer_company', 'Privacy', '/p/privacy', 2],
            ['footer_company', 'Terms', '/p/terms', 3],
        ];

        foreach ($items as [$location, $label, $url, $position]) {
            NavigationItem::updateOrCreate(
                ['location' => $location, 'url' => $url],
                ['label' => $label, 'position' => $position, 'is_active' => true]
            );
        }
    }

    private function pages(): void
    {
        $reviewNote = 'Drafted as a starting point. The owner must review and complete this before launch.';

        $pages = [
            [
                'slug' => 'about',
                'title' => 'About us',
                'excerpt' => 'A small shop for dogs and cats.',
                'is_policy' => false,
                'requires_owner_review' => true,
                'body' => <<<'TEXT'
                We stock a deliberately small range of supplies for dogs and cats: toys, enrichment,
                feeding accessories, grooming tools, travel gear and beds.

                Our stock is held in United States warehouses operated by our fulfilment partner, and
                that is where your order ships from. We do not claim that every item is manufactured
                in the United States — where something is made and where it is stocked are different
                things, and we would rather be accurate than flattering.

                [OWNER TO COMPLETE: your story, who you are, why you started this shop.]
                TEXT,
            ],
            [
                'slug' => 'shipping',
                'title' => 'Shipping',
                'excerpt' => 'How and when your order gets to you.',
                'is_policy' => true,
                'requires_owner_review' => true,
                'body' => <<<'TEXT'
                We ship within the United States.

                HOW DELIVERY TIMES ARE CALCULATED

                We do not advertise a single delivery speed, because delivery time genuinely depends
                on your address, which warehouse holds the item, and which carrier services are
                available for that combination. Enter your ZIP code on a product page, or reach the
                delivery step at checkout, and we will show you the services actually available with
                the carrier's own estimate.

                Where the carrier states a transit time, we show it as a transit time and state our
                handling time separately. Where a carrier publishes no estimate at all, we say so
                rather than guessing.

                HANDLING TIME

                Orders are reviewed before they are sent to our fulfilment partner. Current handling
                time is shown at checkout and is in addition to the carrier's transit estimate.

                SPLIT SHIPMENTS

                If your items are stocked in different warehouses they will arrive in separate
                parcels, each with its own tracking. We tell you this before you pay.

                ALASKA, HAWAII, TERRITORIES AND PO BOXES

                Services and prices for Alaska, Hawaii and the US territories differ from the
                contiguous states, and some available services cannot deliver to PO boxes. Checkout
                will tell you if the address you have entered cannot be served.

                [OWNER TO COMPLETE: confirm cut-off times and any carrier restrictions with your
                fulfilment partner before launch.]
                TEXT,
            ],
            [
                'slug' => 'returns',
                'title' => 'Returns',
                'excerpt' => 'What to do if something is not right.',
                'is_policy' => true,
                'requires_owner_review' => true,
                'body' => <<<'TEXT'
                [DRAFT — NOT YET IN FORCE. The owner must agree return terms with the fulfilment
                partner and complete this page before launch.]

                If something arrives damaged, faulty or not as described, contact us and we will put
                it right.

                POINTS THE OWNER MUST DECIDE AND STATE HERE:
                  - the return window in days
                  - who pays return shipping, and in which circumstances
                  - the return address, once agreed with the fulfilment partner
                  - condition requirements for a return to be accepted
                  - how long a refund takes to reach the customer after we receive the item

                Requesting a refund is not the same as a refund being issued. We will confirm in
                writing when a refund has actually been sent to your payment provider.
                TEXT,
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy policy',
                'excerpt' => 'What we collect and why.',
                'is_policy' => true,
                'requires_owner_review' => true,
                'body' => <<<'TEXT'
                [DRAFT — NOT YET IN FORCE. This is a structural starting point, not legal advice.
                The owner must have this reviewed by a qualified adviser before launch.]

                WHAT WE COLLECT
                  - Contact and delivery details you give us so we can fulfil your order.
                  - Order history.
                  - Technical data needed to run the site securely.

                We do not store card details. Payments are handled by the payment provider you
                choose at checkout.

                WHO WE SHARE IT WITH
                  - Our fulfilment partner, so they can pack and ship your order.
                  - Our payment provider, to take payment.

                SECTIONS THE OWNER MUST COMPLETE
                  - The legal entity acting as data controller and its address.
                  - Retention periods.
                  - How to exercise your rights, and the contact address for doing so.
                  - Any analytics or marketing tools actually in use.
                TEXT,
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms of sale',
                'excerpt' => 'The terms your order is placed under.',
                'is_policy' => true,
                'requires_owner_review' => true,
                'body' => <<<'TEXT'
                [DRAFT — NOT YET IN FORCE. This is a structural starting point, not legal advice.
                The owner must have this reviewed by a qualified adviser before launch.]

                ORDERS
                Placing an order is an offer to buy. We accept it when we confirm dispatch. If we
                cannot fulfil an order we will tell you and refund you in full.

                PRICES
                Prices are shown in US dollars and include any tax shown separately at checkout.
                You see the exact amount, and the exact currency, before you pay.

                SECTIONS THE OWNER MUST COMPLETE
                  - The contracting legal entity and its registered address.
                  - Governing law and jurisdiction.
                  - Cancellation rights and how to exercise them.
                  - Limitation of liability, as advised.
                TEXT,
            ],
        ];

        foreach ($pages as $page) {
            ContentPage::updateOrCreate(
                ['slug' => $page['slug']],
                $page + [
                    'is_published' => true,
                    'review_note' => $reviewNote,
                    'body' => preg_replace('/^ {16}/m', '', $page['body']),
                ]
            );
        }
    }

    private function faqs(): void
    {
        $faqs = [
            ['Ordering', 'Do I need an account to order?', 'No. You can check out as a guest with just your delivery and contact details. Creating an account is optional and simply keeps your order history in one place.'],
            ['Ordering', 'Can I change or cancel an order?', 'Get in touch as soon as you can. If your order has not yet gone to our fulfilment partner we can usually cancel it. Once it has been sent for packing we cannot guarantee a cancellation, and we will tell you honestly which of those applies to your order.'],
            ['Delivery', 'How long will delivery take?', 'It depends on your address and which warehouse holds your items, so we do not advertise a single figure. Enter your ZIP code on any product page and we will show the services available to you with the carrier\'s own estimate.'],
            ['Delivery', 'Why is my order arriving in more than one parcel?', 'Items are sometimes stocked in different warehouses. When that happens they ship separately and each parcel gets its own tracking. We show this at checkout before you pay.'],
            ['Delivery', 'Do you ship to Alaska, Hawaii or PO boxes?', 'Services for Alaska, Hawaii and the US territories differ from the contiguous states, and some services cannot deliver to PO boxes. Checkout will tell you if we cannot serve the address you entered.'],
            ['Products', 'Where are your products made?', 'Our stock is held in US warehouses and ships from there. That is a statement about where it is stored, not about where it was manufactured — we will not claim otherwise.'],
            ['Payment', 'Which currency will I be charged in?', 'Your order is priced in US dollars and you are charged in US dollars. The exact amount and currency are shown before you pay.'],
        ];

        foreach ($faqs as $index => [$category, $question, $answer]) {
            Faq::updateOrCreate(
                ['question' => $question],
                ['category' => $category, 'answer' => $answer, 'position' => $index, 'is_active' => true]
            );
        }
    }
}
