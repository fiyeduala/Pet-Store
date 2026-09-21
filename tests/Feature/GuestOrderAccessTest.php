<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AttachVerifiedGuestOrders;
use App\Domain\Checkout\CheckoutService;
use App\Models\Market;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nobody may read an order they do not control.
 */
class GuestOrderAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        Notification::fake();
    }

    #[Test]
    public function the_order_number_alone_does_not_grant_access(): void
    {
        $order = $this->order();

        $this->get("/orders/{$order->number}/view")->assertStatus(403);
        $this->get("/orders/{$order->number}/view?token=guessed")->assertStatus(403);
    }

    #[Test]
    public function a_valid_signed_link_with_the_right_token_works(): void
    {
        $order = $this->order();
        $token = app(CheckoutService::class)->attachAccessToken($order);

        $url = URL::temporarySignedRoute('orders.track.show', now()->addDay(), [
            'order' => $order->number,
            'token' => $token,
        ]);

        $this->get($url)->assertSuccessful()->assertSee($order->number);
    }

    #[Test]
    public function a_correctly_signed_link_with_the_wrong_token_is_refused(): void
    {
        $order = $this->order();
        app(CheckoutService::class)->attachAccessToken($order);

        // Properly signed, so the signature check passes, but the token is
        // someone else's. Both must match.
        $url = URL::temporarySignedRoute('orders.track.show', now()->addDay(), [
            'order' => $order->number,
            'token' => bin2hex(random_bytes(32)),
        ]);

        $this->get($url)->assertNotFound();
    }

    #[Test]
    public function an_expired_token_is_refused_even_with_a_valid_signature(): void
    {
        $order = $this->order();
        $token = app(CheckoutService::class)->attachAccessToken($order);

        $order->forceFill(['access_token_expires_at' => now()->subDay()])->save();

        $url = URL::temporarySignedRoute('orders.track.show', now()->addDay(), [
            'order' => $order->number,
            'token' => $token,
        ]);

        $this->get($url)->assertStatus(410);
    }

    #[Test]
    public function a_customer_cannot_read_another_customers_order(): void
    {
        $mine = $this->order();
        $theirs = $this->order('someone.else@example.test');

        $me = User::factory()->create(['email' => 'buyer@example.test']);
        $mine->forceFill(['user_id' => $me->id, 'is_guest' => false])->save();

        $this->actingAs($me)->get("/account/orders/{$mine->number}")->assertSuccessful();
        $this->actingAs($me)->get("/account/orders/{$theirs->number}")->assertNotFound();
    }

    #[Test]
    public function the_lookup_form_answers_identically_whether_the_order_exists(): void
    {
        $order = $this->order();

        $this->post('/orders/track', [
            'order_number' => $order->number,
            'email' => 'buyer@example.test',
        ])->assertRedirect();
        $realStatus = session('status');

        $this->flushSession();

        $this->post('/orders/track', [
            'order_number' => 'PS-000000-NOPE',
            'email' => 'nobody@example.test',
        ])->assertRedirect();
        $fakeStatus = session('status');

        // Identical wording, so this cannot be used to enumerate orders.
        $this->assertSame($realStatus, $fakeStatus);
        $this->assertNotNull($realStatus);
    }

    #[Test]
    public function the_lookup_form_requires_the_matching_email(): void
    {
        $order = $this->order();

        $this->post('/orders/track', [
            'order_number' => $order->number,
            'email' => 'attacker@example.test',
        ])->assertSessionHas('status');

        // No mail was sent, because the address did not match the order.
        Notification::assertNothingSent();
    }

    #[Test]
    public function guest_orders_are_not_linked_by_a_typed_email_alone(): void
    {
        $guestOrder = $this->order('shared@example.test');

        // Someone registers with the same address but has NOT verified it.
        $user = User::factory()->unverified()->create(['email' => 'shared@example.test']);

        $linked = app(AttachVerifiedGuestOrders::class)->handle($user);

        $this->assertSame(0, $linked);
        $this->assertNull($guestOrder->fresh()->user_id);

        // Once verified, the link is allowed.
        $user->forceFill(['email_verified_at' => now()])->save();
        $linked = app(AttachVerifiedGuestOrders::class)->handle($user->fresh());

        $this->assertSame(1, $linked);
        $this->assertSame($user->id, $guestOrder->fresh()->user_id);
    }

    private function order(string $email = 'buyer@example.test'): Order
    {
        return Order::create([
            'number' => 'PS-TEST-'.strtoupper(substr(uniqid(), -6)),
            'market_id' => Market::default()->id,
            'currency' => 'USD',
            'is_guest' => true,
            'email' => $email,
            'payment_state' => Order::PAYMENT_PAID,
            'approval_state' => Order::APPROVAL_AWAITING,
            'supplier_order_state' => Order::SUPPLIER_NOT_SUBMITTED,
            'supplier_payment_state' => Order::SUPPLIER_PAY_NOT_PAID,
            'shipment_state' => Order::SHIPMENT_NONE,
            'subtotal_minor' => 2500,
            'total_minor' => 2500,
            'shipping_address' => ['country_code' => 'US', 'postal_code' => '10001', 'line1' => '1 Test St', 'city' => 'NYC', 'state' => 'NY'],
            'placed_at' => now(),
            'paid_at' => now(),
        ]);
    }
}
