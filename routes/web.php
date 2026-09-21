<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront
|--------------------------------------------------------------------------
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/pets/{petType:slug}', [ShopController::class, 'petType'])->name('shop.pet-type');
Route::get('/shop/c/{category:slug}', [ShopController::class, 'category'])->name('shop.category');
Route::get('/collections/{collection:slug}', [ShopController::class, 'collection'])->name('shop.collection');
Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('product.show');

Route::get('/cart', [CartController::class, 'index'])->name('cart.index');

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
*/

Route::prefix('checkout')->name('checkout.')->group(function (): void {
    Route::get('/', [CheckoutController::class, 'index'])->name('index');

    // Payment return/cancel URLs. These NEVER mark an order paid on their
    // own; they trigger a server-side capture and then redirect.
    Route::get('/return/{gateway}/{order:number}', [CheckoutController::class, 'paymentReturn'])
        ->middleware('throttle:30,1')
        ->name('payment.return');
    Route::get('/cancel/{gateway}/{order:number}', [CheckoutController::class, 'paymentCancel'])
        ->name('payment.cancel');

    // Demo-only confirmation screen. Never reachable for a live gateway.
    Route::get('/demo/{order:number}', [CheckoutController::class, 'demoConfirm'])->name('demo.confirm');
    Route::post('/demo/{order:number}', [CheckoutController::class, 'demoSettle'])
        ->middleware('throttle:10,1')
        ->name('demo.settle');

    Route::get('/confirmation/{order:number}', [CheckoutController::class, 'confirmation'])->name('confirmation');
});

/*
|--------------------------------------------------------------------------
| Order tracking
|--------------------------------------------------------------------------
| Guest access requires a signed link carrying a high-entropy token. The
| order number alone is never enough.
*/

Route::get('/orders/track', [OrderTrackingController::class, 'form'])->name('orders.track');
Route::post('/orders/track', [OrderTrackingController::class, 'request'])
    ->middleware('throttle:5,1')
    ->name('orders.track.request');
Route::get('/orders/{order:number}/view', [OrderTrackingController::class, 'show'])
    ->middleware('signed')
    ->name('orders.track.show');

/*
|--------------------------------------------------------------------------
| Account
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('/', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('/orders', [AccountController::class, 'orders'])->name('orders');
    Route::get('/orders/{order:number}', [AccountController::class, 'order'])->name('order');
    Route::get('/addresses', [AccountController::class, 'addresses'])->name('addresses');
    Route::get('/profile', [AccountController::class, 'profile'])->name('profile');
    Route::patch('/profile', [AccountController::class, 'updateProfile'])->name('profile.update');
});

// The orders link in the header works for guests too: it offers tracking.
Route::get('/account/orders', [AccountController::class, 'orders'])
    ->withoutMiddleware('auth')
    ->name('account.orders');

/*
|--------------------------------------------------------------------------
| Content
|--------------------------------------------------------------------------
*/

Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');
Route::get('/faq', [PageController::class, 'faq'])->name('faq');
Route::get('/p/{page:slug}', [PageController::class, 'show'])->name('page');

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
| CSRF-exempt by design; authenticity is proved by provider signature
| verification inside the controller, never by session state.
*/

Route::post('/webhooks/payments/{gateway}', [WebhookController::class, 'payment'])
    ->middleware('throttle:120,1')
    ->name('webhooks.payment');

Route::post('/webhooks/suppliers/{supplier}', [WebhookController::class, 'supplier'])
    ->middleware('throttle:120,1')
    ->name('webhooks.supplier');

require __DIR__.'/auth.php';
